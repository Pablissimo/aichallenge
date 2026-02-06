<?php

/**
 * HTML-escape a string for safe output in HTML context.
 */
function h($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

/**
 * Execute a prepared statement and return the result set.
 *
 * @param string $sql   SQL with ? placeholders
 * @param string $types Parameter types string (e.g. "ssi" for string, string, int)
 * @param mixed  ...$params Parameters to bind
 * @return mysqli_result|bool  Result set on success, false on failure
 */
function prepared_query($sql, $types = '', ...$params) {
    global $mysqli;
    $stmt = mysqli_prepare($mysqli, $sql);
    if (!$stmt) {
        error_log("Prepared statement error: " . mysqli_error($mysqli) . " SQL: " . $sql);
        return false;
    }
    if ($types !== '' && count($params) > 0) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    if (!mysqli_stmt_execute($stmt)) {
        error_log("Prepared statement execution error: " . mysqli_stmt_error($stmt));
        mysqli_stmt_close($stmt);
        return false;
    }
    $result = mysqli_stmt_get_result($stmt);
    if ($result === false) {
        // For INSERT/UPDATE/DELETE, there's no result set
        $affected = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);
        return $affected >= 0;
    }
    // Don't close stmt yet — caller needs to consume the result
    return $result;
}

?>
