<?php

function get_domain($url)
{
    $parsed_url = parse_url($url);
    $host = $parsed_url['host'] ?? $parsed_url['path'];
    preg_match("/^(?:www\.)?(.+)$/i", $host, $matches);

    return $matches[1];
}

function fetch_data($url)
{
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $data = curl_exec($ch);

    curl_close($ch);

    return $data;
}

function parse_results($html, $keywords)
{
    $results = [];
    $dom = new DOMDocument();

    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_use_internal_errors(false);

    $xpath = new DOMXPath($dom);

    foreach ($keywords as $keyword) {
        $nodes = $xpath->query("//a[contains(translate(., 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'), '$keyword')]");
        $count = 0;

        foreach ($nodes as $node) {
            $title = $node->nodeValue;
            $link = $node->getAttribute("href");

            if (!preg_match("/^(https?:\/\/)/", $link)) {
                $link = rtrim($url, "/") . "/" . ltrim($link, "/");
            }

            $results[] = [$title, $link];

            if (++$count >= 2) {
                break;
            }
        }
    }

    return $results;
}

header("Content-Type: application/json");

$input = json_decode(file_get_contents("php://input"), true);
$domain = get_domain($input["domain"]);
$extraKeywords = array_filter(array_map("trim", $input["extraKeywords"]));

$searchUrl = "https://www.$domain/?s=casino";
$html = fetch_data($searchUrl);

if (!$html) {
    http_response_code(500);
    die(json_encode(["error" => "Unable to fetch data"]));
}

$casinoResults = parse_results($html, ["casino"]);

$extraResults = [];
foreach ($extraKeywords as $keyword) {
    $searchUrl = "https://www.$domain/?s=" . urlencode($keyword);
    $html = fetch_data($searchUrl);

    if ($html) {
        $keywordResults = parse_results($html, [$keyword]);
        if (count($keywordResults) > 0) {
            $extraResults = array_merge($extraResults, $keywordResults);
        }
    }
}

$casinoTitles = implode("<br>", array_map(function ($item) {
    return $item[0];
}, $casinoResults));

$casinoLinks = implode("<br>", array_map(function ($item) {
    return $item[1];
}, $casinoResults));

$extraTitles = implode("<br>", array_map(function ($item) {
    return $item[0];
}, $extraResults));

$extraLinks = implode("<br>", array_map(function ($item) {
    return $item[1];
}, $extraResults));

echo json_encode([
    "domain" => $domain,
    "casinoExists" => count($casinoResults) > 0 ? "Evet" : "Hayır",
        "casinoTitles" => $casinoTitles,
    "casinoLinks" => $casinoLinks,
    "extraExists" => count($extraResults) > 0 ? "Evet" : "Hayır",
    "extraTitles" => $extraTitles,
    "extraLinks" => $extraLinks
]);
?>
