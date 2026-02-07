#!/usr/bin/env python
"""S3-compatible artifact store for compiled bot submissions.

Stores compiled submission directories as tar.gz archives in an S3 bucket
(typically MinIO). Used as a durable backing store with the local filesystem
acting as a fast cache.

All operations are best-effort: failures are logged but never raised, so
the worker continues to function even if the artifact store is unavailable.
"""

import io
import os
import tarfile
import logging

import boto3
from botocore.exceptions import ClientError

log = logging.getLogger('worker')


class ArtifactStore:
    def __init__(self, endpoint, access_key, secret_key, bucket):
        self.bucket = bucket
        self.client = boto3.client(
            's3',
            endpoint_url=endpoint,
            aws_access_key_id=access_key,
            aws_secret_access_key=secret_key,
            region_name='us-east-1',  # required by boto3, ignored by MinIO
        )
        self._ensure_bucket()

    def _ensure_bucket(self):
        """Create the bucket if it doesn't already exist."""
        try:
            self.client.head_bucket(Bucket=self.bucket)
        except ClientError:
            try:
                self.client.create_bucket(Bucket=self.bucket)
                log.info("Created S3 bucket: %s" % self.bucket)
            except Exception as e:
                log.warning("Could not create S3 bucket %s: %s" % (self.bucket, e))

    def _s3_key(self, submission_id):
        """S3 object key for a submission, mirroring local directory structure."""
        return "compiled/%d/%d.tar.gz" % (submission_id // 1000, submission_id)

    def upload(self, submission_id, submission_dir):
        """Tar and upload a compiled submission directory to S3.

        Includes bot/, run.sh, and manifest.json. Best-effort: logs errors
        but does not raise.
        """
        try:
            buf = io.BytesIO()
            with tarfile.open(fileobj=buf, mode='w:gz') as tar:
                for name in os.listdir(submission_dir):
                    full_path = os.path.join(submission_dir, name)
                    tar.add(full_path, arcname=name)
            buf.seek(0)
            data = buf.getvalue()

            key = self._s3_key(submission_id)
            self.client.put_object(
                Bucket=self.bucket,
                Key=key,
                Body=data,
            )
            log.info("Uploaded submission %d to artifact store (%s, %d bytes)"
                     % (submission_id, key, len(data)))
        except Exception as e:
            log.warning("Failed to upload submission %d to artifact store: %s"
                        % (submission_id, e))

    def download(self, submission_id, target_dir):
        """Download and extract a submission archive from S3 to target_dir.

        Returns True if successful, False if not found or on error.
        """
        try:
            key = self._s3_key(submission_id)
            response = self.client.get_object(Bucket=self.bucket, Key=key)
            data = response['Body'].read()

            buf = io.BytesIO(data)
            with tarfile.open(fileobj=buf, mode='r:gz') as tar:
                tar.extractall(path=target_dir)

            log.info("Downloaded submission %d from artifact store (%d bytes)"
                     % (submission_id, len(data)))
            return True
        except ClientError as e:
            if e.response['Error']['Code'] == 'NoSuchKey':
                log.debug("Submission %d not in artifact store" % submission_id)
            else:
                log.warning("Failed to download submission %d from artifact store: %s"
                            % (submission_id, e))
            return False
        except Exception as e:
            log.warning("Failed to download submission %d from artifact store: %s"
                        % (submission_id, e))
            return False

    def exists(self, submission_id):
        """Check if a submission exists in the artifact store (HEAD request)."""
        try:
            self.client.head_object(
                Bucket=self.bucket,
                Key=self._s3_key(submission_id),
            )
            return True
        except ClientError:
            return False
        except Exception as e:
            log.warning("Failed to check artifact store for submission %d: %s"
                        % (submission_id, e))
            return False
