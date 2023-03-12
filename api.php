<?php

date_default_timezone_set('UTC');

if (!file_exists(__DIR__ . DIRECTORY_SEPARATOR . ".env"))
    die('Application configuration not found.');

// Parse dotenv file
$env = file(__DIR__ . DIRECTORY_SEPARATOR . ".env");
foreach ($env as $row) {
    $matches = array();

    if (preg_match("/^(?!#)([A-Za-z_]{1,})\=(.*?)$/si", $row, $matches)) {
        if (!putenv($matches[1] . "=" . trim($matches[2], "\""))) {
            die('Fatal error while parsing configuration, please check configuration file.');
        }
    }
}

switch (getenv("ENVIRONMENT")) {
    case "production":
        error_reporting(NULL);
        ini_set("display_errors", "0");
        break;
    default:
        error_reporting(E_ALL);
        ini_set("display_errors", "1");
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $db = new mysqli(getenv('MYSQL_HOSTNAME'), getenv('MYSQL_USERNAME'), getenv('MYSQL_PASSWORD'), getenv('MYSQL_DATABASE'));
} catch (Exception $e) {
    die('Database connection not established, please check application configuration');
}

if (getenv("BENCHMARK") === "TRUE") {
    $benchmark_start = microtime(TRUE);
    $benchmark_point = microtime(TRUE);
    $benchmarks = array();
}

if (!isset($_GET['key']) || $_GET['key'] !== getenv('API_KEY')) {
    http_response_code(403);
    exit;
}

$get = isset($_GET['get']) ? trim(filter_var($_GET['get'], FILTER_SANITIZE_FULL_SPECIAL_CHARS)) : NULL;
switch ($get) {
    case 'map':
        $filter_from_date = NULL;
        $filter_from = isset($_GET['from']) ? trim(filter_var($_GET['from'], FILTER_SANITIZE_FULL_SPECIAL_CHARS)) : NULL;
        if ($filter_from) {
            $from_timestamp = strtotime($filter_from);
            if ($from_timestamp && $from_timestamp >= strtotime("1 year ago"))
                $filter_from_date = date("Y-m-d H:i:s", $from_timestamp);
        }

        $filter_call_sign = isset($_GET['call_sign']) ? trim(filter_var($_GET['call_sign'], FILTER_SANITIZE_FULL_SPECIAL_CHARS)) : NULL;

        if (getenv("BENCHMARK") === "TRUE") {
            $benchmarks['filters'] = round(microtime(TRUE) - $benchmark_point, 5);
            $benchmark_point = microtime(TRUE);
        }

        $stations = array();
        $call_signs_where = array(
            "`latitude` IS NOT NULL",
            "`longitude` IS NOT NULL"
        );
        $call_signs_params = array();
        if ($filter_call_sign) {
            $call_signs_where[] = "`from` = ?";
            $call_signs_params[] = $filter_call_sign;
        }
        if($filter_from_date) {
            $call_signs_where[] = "`date` >= ?";
            $call_signs_params[] = $filter_from_date;
        }
        $call_signs_query = "SELECT
            `from`
        FROM
            `history`
        " . (count($call_signs_where) > 0 ? "WHERE " . implode(" AND ", $call_signs_where) . " " : "") . "
        GROUP BY
            `from`;";
        $call_signs_stmt = $db->prepare($call_signs_query);
        if (count($call_signs_params) > 0) {
            $call_signs_stmt->bind_param(str_repeat('s', count($call_signs_params)), ...$call_signs_params);
        }
        $call_signs_stmt->execute();
        $call_signs_result = $call_signs_stmt->get_result();

        if (getenv("BENCHMARK") === "TRUE") {
            $benchmarks['call_signs_query'] = round(microtime(TRUE) - $benchmark_point, 5);
            $benchmark_point = microtime(TRUE);

            $query_idx = 1;
        }

        while ($call_signs_row = $call_signs_result->fetch_object()) {
            $history_where = array(
                "`from` = ?",
                "`latitude` IS NOT NULL",
                "`longitude` IS NOT NULL"
            );
            $history_params = array(
                $call_signs_row->from
            );
            if($filter_from_date) {
                $history_where[] = "`date` >= ?";
                $history_params[] = $filter_from_date;
            }
            $history_query = "SELECT
                `date`,
                `comment`,
                `latitude`,
                `longitude`,
                `symbol_table`,
                `symbol`
            FROM
                `history`
            " . (count($history_where) > 0 ? "WHERE " . implode(" AND ", $history_where) . " " : "") . "
            ORDER BY `date` DESC LIMIT 1
            ;"; // Only last point if timespan more than 10 days
            $history_stmt = $db->prepare($history_query);
            if (count($history_params) > 0) {
                $history_stmt->bind_param(str_repeat('s', count($history_params)), ...$history_params);
            }
            $history_stmt->execute();
            $history_result = $history_stmt->get_result();

            while ($row = $history_result->fetch_object()) {
                $packet = array(
                    "t" => strtotime($row->date),
                    "lat" => $row->latitude,
                    "lng" => $row->longitude,
                );
                if ($row->comment) $packet['c'] = $row->comment;
                if ($row->symbol_table) $packet['st'] = $row->symbol_table;
                if ($row->symbol) $packet['s'] = $row->symbol;

                $stations[$call_signs_row->from] = $packet;
            }
            $history_result->close();
        }
        $call_signs_result->close();

        if (getenv("BENCHMARK") === "TRUE") {
            $benchmarks['history_queries'] = round(microtime(TRUE) - $benchmark_point, 5);
            $benchmark_point = microtime(TRUE);
        }

        $http_response_code = 200;
        $response = array(
            'data' => $stations
        );
        break;
    default:
        $http_response_code = 400;
        $response = array();
}

if (getenv("BENCHMARK") === "TRUE") {
    $benchmarks['end'] = round(microtime(TRUE) - $benchmark_point, 5);
    $benchmarks['run'] = round(microtime(TRUE) - $benchmark_start, 5);
    $response = array_merge($response, array(
        'benchmarks' => $benchmarks
    ));
}

http_response_code($http_response_code);
header("Content-type:application/json");
echo json_encode($response);
exit;