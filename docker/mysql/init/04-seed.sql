-- Seed data for local Docker development

-- Insert a worker record for the local worker container
INSERT IGNORE INTO worker (ip_address, api_key) VALUES ('0.0.0.0', 'worker_api_key_local');

-- Note: pairing_cutoff is intentionally NOT inserted; when absent,
-- generate_matchup sets @pairing_cutoff=NULL which means no cutoff.

-- Insert user status codes
INSERT IGNORE INTO user_status_code (status_id, name) VALUES (1, 'Active');
INSERT IGNORE INTO user_status_code (status_id, name) VALUES (2, 'Inactive');

-- Create a minimal generate_leaderboard procedure
-- (not in the original repo but called by manager.py)
delimiter $$
drop procedure if exists generate_leaderboard$$
create procedure generate_leaderboard()
begin
    -- Add a small amount of sigma to active submissions to encourage rematches
    update submission
    set sigma = least(sigma + 0.001, 16.6667)
    where latest = 1 and status = 40;

    -- Reorder rankings by skill (mu - 3*sigma)
    update submission
    inner join (
        select
               s.submission_id,
               @skill := mu - sigma * 3 as skill,
               @seq := (@seq + 1) as seq,
               if(@skill = @last_skill, @last_rank, @seq) as new_rank,
               if(@skill = @last_skill, @last_rank, @last_rank := @seq) next_rank,
               @last_skill := @skill
        from (
            select *
            from submission s
            where s.latest = 1 and s.status = 40
            order by s.mu - s.sigma * 3 desc
        ) s,
        (select @skill := 0.0) k,
        (select @seq := 0) r,
        (select @last_skill := null) lk,
        (select @last_rank := 0) lr
    ) s2
        on submission.submission_id = s2.submission_id
    set rank = s2.new_rank
    where submission.latest = 1;
end$$
delimiter ;
