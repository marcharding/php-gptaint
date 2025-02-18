# SQL-Queries to interpret the results

## Get all TP results for given analyzer (very slow)

Adjust query for other cases (TN, FP, FN).

```sql
SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci';
SET @analyzer = 'gpt-4o';
SELECT analysis_result.*
FROM analysis_result
LEFT JOIN sample ON analysis_result.issue_id = sample.id
WHERE analyzer = @analyzer
AND analysis_result.id IN
    (
        SELECT MAX(id)
        FROM analysis_result
        WHERE analyzer = @analyzer
        GROUP BY issue_id
    )
AND exploit_example_successful = 1
AND sample.confirmed_state = 1;
```

## Get summarized statistics for all analyzers (with Feedback)

```sql
SELECT
    ar.analyzer,
    COUNT(CASE WHEN ar.result_state = 1 AND s.confirmed_state = 1 THEN 1 END) AS TP,
    COUNT(CASE WHEN ar.result_state = 0 AND s.confirmed_state = 0 THEN 1 END) AS TN,
    COUNT(CASE WHEN ar.result_state = 0 AND s.confirmed_state = 1 THEN 1 END) AS FN,
    COUNT(CASE WHEN ar.result_state = 1 AND s.confirmed_state = 0 THEN 1 END) AS FP
FROM
    analysis_result ar
        JOIN
    (SELECT MAX(id) AS max_id, issue_id, analyzer
     FROM analysis_result
     GROUP BY issue_id, analyzer
    ) AS max_results
    ON ar.id = max_results.max_id
        LEFT JOIN
    sample s
    ON ar.issue_id = s.id
GROUP BY
    ar.analyzer
```

## Get summarized statistics for all analyzers (without Feedback)

```sql
SELECT
    ar.analyzer,
    COUNT(CASE WHEN ar.result_state = 1 AND s.confirmed_state = 1 THEN 1 END) AS TP,
    COUNT(CASE WHEN ar.result_state = 0 AND s.confirmed_state = 0 THEN 1 END) AS TN,
    COUNT(CASE WHEN ar.result_state = 0 AND s.confirmed_state = 1 THEN 1 END) AS FN,
    COUNT(CASE WHEN ar.result_state = 1 AND s.confirmed_state = 0 THEN 1 END) AS FP
FROM
    analysis_result ar
        JOIN
    (SELECT MIN(id) AS max_id, issue_id, analyzer
     FROM analysis_result
     GROUP BY issue_id, analyzer
    ) AS max_results
    ON ar.id = max_results.max_id
        LEFT JOIN
    sample s
    ON ar.issue_id = s.id
GROUP BY
    ar.analyzer
```

## Detailed stats per issue (state, try-count)

```sql
SELECT
    s.name as Sample,
    ar.analyzer as Analyzer,
    COUNT(sub_ar.id) AS `Feedback-Loops`,
    CASE
        WHEN ar.result_state = 1 AND s.confirmed_state = 1 THEN 'TP'
        WHEN ar.result_state = 0 AND s.confirmed_state = 0 THEN 'TN'
        WHEN ar.result_state = 0 AND s.confirmed_state = 1 THEN 'FN'
        WHEN ar.result_state = 1 AND s.confirmed_state = 0 THEN 'FP'
        END AS Ergebnis
INTO OUTFILE '/var/lib/mysql-files/ergebnisse_vollstaendig_20250105.csv'
FIELDS TERMINATED BY ';'
ENCLOSED BY '"'
LINES TERMINATED BY '\n'
FROM
    analysis_result ar
        JOIN
    (SELECT MAX(id) AS max_id, issue_id, analyzer
     FROM analysis_result
     GROUP BY issue_id, analyzer
    ) AS max_results
    ON ar.id = max_results.max_id
        LEFT JOIN
    sample s
    ON ar.issue_id = s.id
        LEFT JOIN
    analysis_result sub_ar
    ON ar.issue_id = sub_ar.issue_id AND ar.analyzer = sub_ar.analyzer
GROUP BY
    ar.id, s.name, ar.analyzer, s.confirmed_state, ar.result_state
ORDER BY 
    ar.analyzer;
```

## Detailed stats per issue (state, try-count with added unique exploit count)

```sql
WITH ExploitExamplesCount AS (
    SELECT
        issue_id,
        analyzer,
        COUNT(DISTINCT exploit_example) AS distinct_exploit_count
    FROM
        analysis_result
    GROUP BY
        issue_id,
        analyzer
)

SELECT
    s.name,
    ar.analyzer,
    COUNT(sub_ar.id) AS analysis_count,
    ex_count.distinct_exploit_count AS distinct_exploit_example_count,
    CASE
        WHEN ar.result_state = 1 AND s.confirmed_state = 1 THEN 'TP'
        WHEN ar.result_state = 0 AND s.confirmed_state = 0 THEN 'TN'
        WHEN ar.result_state = 0 AND s.confirmed_state = 1 THEN 'FN'
        WHEN ar.result_state = 1 AND s.confirmed_state = 0 THEN 'FP'
        END AS condition_result
FROM
    analysis_result ar
        JOIN
    (SELECT MAX(id) AS max_id, issue_id, analyzer
     FROM analysis_result
     GROUP BY issue_id, analyzer
    ) AS max_results
    ON ar.id = max_results.max_id
        LEFT JOIN
    sample s
    ON ar.issue_id = s.id
        LEFT JOIN
    analysis_result sub_ar
    ON ar.issue_id = sub_ar.issue_id AND ar.analyzer = sub_ar.analyzer
        LEFT JOIN
    ExploitExamplesCount ex_count
    ON ar.issue_id = ex_count.issue_id AND ar.analyzer = ex_count.analyzer
GROUP BY
    ar.id, s.name, ar.analyzer, s.confirmed_state, ar.result_state, ex_count.distinct_exploit_count;
```


### Differences between two analyzer runs (manual process)

```sql
SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci';
SET @analyzer_one = 'gpt-4o-mini / run 1'; 
SET @analyzer_two = 'gpt-4o-mini';

SELECT
    s.name,
    @analyzer_one AS analyzer1,
    @analyzer_two AS analyzer2,
    CASE
        WHEN ar1.result_state = 1 AND s.confirmed_state = 1 THEN 'TP'
        WHEN ar1.result_state = 0 AND s.confirmed_state = 0 THEN 'TN'
        WHEN ar1.result_state = 1 AND s.confirmed_state = 0 THEN 'FP'
        WHEN ar1.result_state = 0 AND s.confirmed_state = 1 THEN 'FN'
        END AS condition_result_analyzer1,
    CASE
        WHEN ar2.result_state = 1 AND s.confirmed_state = 1 THEN 'TP'
        WHEN ar2.result_state = 0 AND s.confirmed_state = 0 THEN 'TN'
        WHEN ar2.result_state = 1 AND s.confirmed_state = 0 THEN 'FP'
        WHEN ar2.result_state = 0 AND s.confirmed_state = 1 THEN 'FN'
        END AS condition_result_analyzer2,
    CASE
        WHEN
            CASE
                WHEN ar1.result_state = 1 AND s.confirmed_state = 1 THEN 'TP'
                WHEN ar1.result_state = 0 AND s.confirmed_state = 0 THEN 'TN'
                WHEN ar1.result_state = 1 AND s.confirmed_state = 0 THEN 'FP'
                WHEN ar1.result_state = 0 AND s.confirmed_state = 1 THEN 'FN'
                END
                <>
            CASE
                WHEN ar2.result_state = 1 AND s.confirmed_state = 1 THEN 'TP'
                WHEN ar2.result_state = 0 AND s.confirmed_state = 0 THEN 'TN'
                WHEN ar2.result_state = 1 AND s.confirmed_state = 0 THEN 'FP'
                WHEN ar2.result_state = 0 AND s.confirmed_state = 1 THEN 'FN'
                END
            THEN 'Different'
        ELSE 'Same'
        END AS result_difference
FROM
    (SELECT issue_id, MAX(id) AS max_id
     FROM analysis_result
     WHERE analyzer = @analyzer_one
     GROUP BY issue_id) AS max_results1
        JOIN
    analysis_result ar1
    ON max_results1.max_id = ar1.id
        JOIN
    (SELECT issue_id, MAX(id) AS max_id
     FROM analysis_result
     WHERE analyzer = @analyzer_two
     GROUP BY issue_id) AS max_results2
    ON max_results1.issue_id = max_results2.issue_id
        JOIN
    analysis_result ar2
    ON max_results2.max_id = ar2.id
        LEFT JOIN
    sample s
    ON ar1.issue_id = s.id
GROUP BY
    s.name, ar1.result_state, ar2.result_state, s.confirmed_state;
```

# Differences between two analyzer runs (manual process), grouped by category (tp, tn, fp, fn)

```sql
SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci';

SET @analyzer_one = 'llama3.3-70b';
SET @analyzer_two = 'llama3.3-70b / run 2';
SET @analyzer_one = 'llama3.1-8b';
SET @analyzer_two = 'llama3.1-8b / run 2';
SET @analyzer_one = 'gpt-4o-mini';
SET @analyzer_two = 'gpt-4o-mini / run 2';
SET @analyzer_one = 'gpt-4o';
SET @analyzer_two = 'gpt-4o / run 2';
SET @analyzer_one = 'gpt-3.5-turbo';
SET @analyzer_two = 'gpt-3.5-turbo / run 2';

WITH analyzer_results AS (
    SELECT
        s.name,
        @analyzer_one AS analyzer1,
        @analyzer_two AS analyzer2,
        CASE
            WHEN ar1.result_state = 1 AND s.confirmed_state = 1 THEN 'TP'
            WHEN ar1.result_state = 0 AND s.confirmed_state = 0 THEN 'TN'
            WHEN ar1.result_state = 1 AND s.confirmed_state = 0 THEN 'FP'
            WHEN ar1.result_state = 0 AND s.confirmed_state = 1 THEN 'FN'
            END AS condition_result_analyzer1,
        CASE
            WHEN ar2.result_state = 1 AND s.confirmed_state = 1 THEN 'TP'
            WHEN ar2.result_state = 0 AND s.confirmed_state = 0 THEN 'TN'
            WHEN ar2.result_state = 1 AND s.confirmed_state = 0 THEN 'FP'
            WHEN ar2.result_state = 0 AND s.confirmed_state = 1 THEN 'FN'
            END AS condition_result_analyzer2,
        CASE
            WHEN
                CASE
                    WHEN ar1.result_state = 1 AND s.confirmed_state = 1 THEN 'TP'
                    WHEN ar1.result_state = 0 AND s.confirmed_state = 0 THEN 'TN'
                    WHEN ar1.result_state = 1 AND s.confirmed_state = 0 THEN 'FP'
                    WHEN ar1.result_state = 0 AND s.confirmed_state = 1 THEN 'FN'
                    END
                    <>
                CASE
                    WHEN ar2.result_state = 1 AND s.confirmed_state = 1 THEN 'TP'
                    WHEN ar2.result_state = 0 AND s.confirmed_state = 0 THEN 'TN'
                    WHEN ar2.result_state = 1 AND s.confirmed_state = 0 THEN 'FP'
                    WHEN ar2.result_state = 0 AND s.confirmed_state = 1 THEN 'FN'
                    END
                THEN 'Different'
            ELSE 'Same'
            END AS result_difference
    FROM
        (SELECT issue_id, MAX(id) AS max_id
         FROM analysis_result
         WHERE analyzer = @analyzer_one
         GROUP BY issue_id) AS max_results1
            JOIN
        analysis_result ar1
        ON max_results1.max_id = ar1.id
            JOIN
        (SELECT issue_id, MAX(id) AS max_id
         FROM analysis_result
         WHERE analyzer = @analyzer_two
         GROUP BY issue_id) AS max_results2
        ON max_results1.issue_id = max_results2.issue_id
            JOIN
        analysis_result ar2
        ON max_results2.max_id = ar2.id
            LEFT JOIN
        sample s
        ON ar1.issue_id = s.id
)

SELECT
    condition_result_analyzer1,
    condition_result_analyzer2,
    COUNT(*) AS count
FROM
    analyzer_results
GROUP BY
    condition_result_analyzer1,
    condition_result_analyzer2;

-- SELECT
--     COUNT(*) AS count
-- FROM
--     analyzer_results
-- WHERE
--     result_difference = 'Different';

```

# Differences between two analyzer runs (manual process), detailed result per issue, export CSV

```sql
SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci';

SET @analyzer_one = 'llama3.3-70b';
SET @analyzer_two = 'llama3.3-70b / run 2';
SET @analyzer_one = 'llama3.1-8b';
SET @analyzer_two = 'llama3.1-8b / run 2';
SET @analyzer_one = 'gpt-4o-mini';
SET @analyzer_two = 'gpt-4o-mini / run 2';
SET @analyzer_one = 'gpt-4o';
SET @analyzer_two = 'gpt-4o / run 2';
SET @analyzer_one = 'gpt-3.5-turbo';
SET @analyzer_two = 'gpt-3.5-turbo / run 2';
         
WITH analyzer_results AS (
    SELECT
        s.name,
        @analyzer_one AS analyzer1,
        @analyzer_two AS analyzer2,
        CASE
            WHEN ar1.result_state = 1 AND s.confirmed_state = 1 THEN 'TP'
            WHEN ar1.result_state = 0 AND s.confirmed_state = 0 THEN 'TN'
            WHEN ar1.result_state = 1 AND s.confirmed_state = 0 THEN 'FP'
            WHEN ar1.result_state = 0 AND s.confirmed_state = 1 THEN 'FN'
            END AS condition_result_analyzer1,
        CASE
            WHEN ar2.result_state = 1 AND s.confirmed_state = 1 THEN 'TP'
            WHEN ar2.result_state = 0 AND s.confirmed_state = 0 THEN 'TN'
            WHEN ar2.result_state = 1 AND s.confirmed_state = 0 THEN 'FP'
            WHEN ar2.result_state = 0 AND s.confirmed_state = 1 THEN 'FN'
            END AS condition_result_analyzer2,
        CASE
            WHEN
                CASE
                    WHEN ar1.result_state = 1 AND s.confirmed_state = 1 THEN 'TP'
                    WHEN ar1.result_state = 0 AND s.confirmed_state = 0 THEN 'TN'
                    WHEN ar1.result_state = 1 AND s.confirmed_state = 0 THEN 'FP'
                    WHEN ar1.result_state = 0 AND s.confirmed_state = 1 THEN 'FN'
                    END
                    <>
                CASE
                    WHEN ar2.result_state = 1 AND s.confirmed_state = 1 THEN 'TP'
                    WHEN ar2.result_state = 0 AND s.confirmed_state = 0 THEN 'TN'
                    WHEN ar2.result_state = 1 AND s.confirmed_state = 0 THEN 'FP'
                    WHEN ar2.result_state = 0 AND s.confirmed_state = 1 THEN 'FN'
                    END
                THEN 'Different'
            ELSE 'Same'
            END AS result_difference
    FROM
        (SELECT issue_id, MAX(id) AS max_id
        FROM analysis_result
        WHERE analyzer = @analyzer_one
        GROUP BY issue_id) AS max_results1
    JOIN
        analysis_result ar1
    ON max_results1.max_id = ar1.id
    JOIN
        (SELECT issue_id, MAX(id) AS max_id
        FROM analysis_result
        WHERE analyzer = @analyzer_two
        GROUP BY issue_id) AS max_results2
    ON max_results1.issue_id = max_results2.issue_id
    JOIN
        analysis_result ar2
    ON max_results2.max_id = ar2.id
    LEFT JOIN
        sample s
    ON ar1.issue_id = s.id
)

SELECT name, condition_result_analyzer1, condition_result_analyzer2
INTO OUTFILE '/var/lib/mysql-files/ANALYZER.csv'
     FIELDS TERMINATED BY ';'
     ENCLOSED BY '"'
     LINES TERMINATED BY '\n'
FROM
    analyzer_results
WHERE
    result_difference = 'Different';
```

# Differences between two analyzer runs (manual process), grouped by category (tp, tn, fp, fn)

```sql
SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci';

SET @analyzer_one = 'llama-32-8b / run 2';
SET @analyzer_two = 'llama-32-8b';
SET @analyzer_one = 'gpt-4o-mini / run 2';
SET @analyzer_two = 'gpt-4o-mini';
SET @analyzer_one = 'gpt-4o / run 2';
SET @analyzer_two = 'gpt-4o';
SET @analyzer_one = 'gpt-3.5-turbo / run 2';
SET @analyzer_two = 'gpt-3.5-turbo';
         
WITH analyzer_results AS (
    SELECT
        s.name,
        @analyzer_one AS analyzer1,
        @analyzer_two AS analyzer2,
        CASE
            WHEN ar1.result_state = 1 AND s.confirmed_state = 1 THEN 'TP'
            WHEN ar1.result_state = 0 AND s.confirmed_state = 0 THEN 'TN'
            WHEN ar1.result_state = 1 AND s.confirmed_state = 0 THEN 'FP'
            WHEN ar1.result_state = 0 AND s.confirmed_state = 1 THEN 'FN'
            END AS condition_result_analyzer1,
        CASE
            WHEN ar2.result_state = 1 AND s.confirmed_state = 1 THEN 'TP'
            WHEN ar2.result_state = 0 AND s.confirmed_state = 0 THEN 'TN'
            WHEN ar2.result_state = 1 AND s.confirmed_state = 0 THEN 'FP'
            WHEN ar2.result_state = 0 AND s.confirmed_state = 1 THEN 'FN'
            END AS condition_result_analyzer2,
        CASE
            WHEN
                CASE
                    WHEN ar1.result_state = 1 AND s.confirmed_state = 1 THEN 'TP'
                    WHEN ar1.result_state = 0 AND s.confirmed_state = 0 THEN 'TN'
                    WHEN ar1.result_state = 1 AND s.confirmed_state = 0 THEN 'FP'
                    WHEN ar1.result_state = 0 AND s.confirmed_state = 1 THEN 'FN'
                    END
                    <>
                CASE
                    WHEN ar2.result_state = 1 AND s.confirmed_state = 1 THEN 'TP'
                    WHEN ar2.result_state = 0 AND s.confirmed_state = 0 THEN 'TN'
                    WHEN ar2.result_state = 1 AND s.confirmed_state = 0 THEN 'FP'
                    WHEN ar2.result_state = 0 AND s.confirmed_state = 1 THEN 'FN'
                    END
                THEN 'Different'
            ELSE 'Same'
            END AS result_difference
    FROM
        (SELECT issue_id, MAX(id) AS max_id
        FROM analysis_result
        WHERE analyzer = @analyzer_one
        GROUP BY issue_id) AS max_results1
    JOIN
        analysis_result ar1
    ON max_results1.max_id = ar1.id
    JOIN
        (SELECT issue_id, MAX(id) AS max_id
        FROM analysis_result
        WHERE analyzer = @analyzer_two
        GROUP BY issue_id) AS max_results2
    ON max_results1.issue_id = max_results2.issue_id
    JOIN
        analysis_result ar2
    ON max_results2.max_id = ar2.id
    LEFT JOIN
        sample s
    ON ar1.issue_id = s.id
)

SELECT
    condition_result_analyzer1,
    condition_result_analyzer2,
    COUNT(*) AS count
INTO OUTFILE '/var/lib/mysql-files/ANALYZER.csv'
    FIELDS TERMINATED BY ';'
    ENCLOSED BY '"'
    LINES TERMINATED BY '\n'
FROM
    analyzer_results
GROUP BY
    condition_result_analyzer1,
    condition_result_analyzer2;
```

# Export unique exploits per sample

```sql
WITH ExploitExamplesCount AS (
    SELECT
        issue_id,
        analyzer,
        COUNT(DISTINCT exploit_example) AS distinct_exploit_count
    FROM
        analysis_result
    WHERE
        exploit_example IS NOT NULL
      AND exploit_example != ''  -- No empty exploits
GROUP BY
    issue_id,
    analyzer
    ),

    ResultSummary AS (
SELECT
    ar.analyzer,
    CASE
    WHEN ar.result_state = 1 AND s.confirmed_state = 1 THEN 'TP'
    WHEN ar.result_state = 0 AND s.confirmed_state = 0 THEN 'TN'
    WHEN ar.result_state = 0 AND s.confirmed_state = 1 THEN 'FN'
    WHEN ar.result_state = 1 AND s.confirmed_state = 0 THEN 'FP'
    END AS result_type,
    COUNT(DISTINCT ar.issue_id) AS issue_count,
    COUNT(DISTINCT sub_ar.id) AS total_analysis_count,
    COALESCE(ex_count.distinct_exploit_count, 0) AS unique_exploit_count,
    GROUP_CONCAT(DISTINCT s.name SEPARATOR ', ') AS sample_names
FROM
    analysis_result ar
    JOIN
    (SELECT MAX(id) AS max_id, issue_id, analyzer
    FROM analysis_result
    GROUP BY issue_id, analyzer) AS max_results
ON ar.id = max_results.max_id
    LEFT JOIN
    sample s ON ar.issue_id = s.id
    LEFT JOIN
    analysis_result sub_ar
    ON ar.issue_id = sub_ar.issue_id
    AND ar.analyzer = sub_ar.analyzer
    LEFT JOIN
    ExploitExamplesCount ex_count
    ON ar.issue_id = ex_count.issue_id
    AND ar.analyzer = ex_count.analyzer
GROUP BY
    ar.analyzer,
    ar.result_state,
    s.confirmed_state,
    ar.issue_id,
    ex_count.distinct_exploit_count
    )

SELECT
    analyzer AS 'Analyzer',
        result_type AS 'Ergebnistyp',
        COUNT(*) AS 'Anzahl Ergebnisse',
        SUM(total_analysis_count) AS 'Versuche inkl. Feedback',
        SUM(unique_exploit_count) AS 'Eindeutige Exploits'
INTO OUTFILE '/var/lib/mysql-files/foo.csv'
  FIELDS TERMINATED BY ';' OPTIONALLY ENCLOSED BY '"'
  LINES TERMINATED BY '\n'
FROM
    ResultSummary
WHERE
    result_type IS NOT NULL
GROUP BY
    analyzer,
    result_type
ORDER BY
    analyzer,
    result_type
```
