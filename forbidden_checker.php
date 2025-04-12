<?php

declare(strict_types=1); // Enable strict types for better type safety

/**
 * ==================================
 * Configuration
 * ==================================
 */
const MAX_CASINO_RESULTS = 3;          // Max results to show for "casino" keyword
const MAX_EXTRA_KEYWORD_RESULTS = 2; // Max results to show *per* extra keyword
const MAX_CONCURRENT_REQUESTS = 5;   // Max simultaneous requests from the browser (JavaScript)
const REQUEST_TIMEOUT = 15;          // cURL request timeout in seconds
const USER_AGENT = 'AdvancedForbiddenContentChecker/3.0 (PHP/8.3; +https://www.example.com/checker-info)'; // Custom User-Agent

/**
 * ==================================
 * Backend Functions (PHP)
 * ==================================
 */

/**
 * Fetches content from a given URL using cURL with enhanced options.
 *
 * @param string $url The URL to fetch.
 * @return array{success: bool, content: string|null, error: string|null, httpCode: int} Result array.
 */
function fetchData(string $url): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,       // Return the transfer as a string
        CURLOPT_FOLLOWLOCATION => true,       // Follow redirects
        CURLOPT_MAXREDIRS => 5,               // Limit redirects
        CURLOPT_TIMEOUT => REQUEST_TIMEOUT, // Set timeout
        CURLOPT_USERAGENT => USER_AGENT,      // Set a user agent
        CURLOPT_SSL_VERIFYPEER => true,       // Verify SSL certificate
        CURLOPT_SSL_VERIFYHOST => 2,          // Verify SSL host
        CURLOPT_ENCODING => '',              // Handle gzip/deflate encoding
        // CURLOPT_CAINFO => '/path/to/cacert.pem', // Optional: Specify CA bundle path if needed
        CURLOPT_FAILONERROR => false,         // Don't fail silently on 4xx/5xx codes, get the content
    ]);

    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErrorNum = curl_errno($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    $result = [
        'success' => false,
        'content' => null,
        'error' => null,
        'httpCode' => $httpCode,
    ];

    if ($curlErrorNum !== 0) {
        $result['error'] = "cURL Error ({$curlErrorNum}): {$curlError}";
    } elseif ($httpCode >= 400) {
        // Even with errors, sometimes content is returned (e.g., custom 404 pages)
        // We still try to parse it, but report the HTTP error.
        $result['error'] = "HTTP Error: {$httpCode}";
        $result['content'] = ($html !== false) ? $html : null; // Keep content if available
        // Success remains false if HTTP code >= 400
    } elseif ($html === false) {
        // Should not happen if curlErrorNum is 0 and httpCode < 400, but check just in case
        $result['error'] = "Unknown cURL error (html is false)";
    } else {
        $result['success'] = true;
        $result['content'] = $html;
    }

    return $result;
}

/**
 * Parses HTML content to find links containing specific keywords (case-insensitive).
 * Limits the number of results found per keyword.
 * Adds matched keyword information to the result.
 *
 * @param string $html The HTML content to parse.
 * @param string $baseUrl The base URL for resolving relative links.
 * @param string $keyword The keyword to search for.
 * @param int $limit Maximum number of results to return for this keyword.
 * @return array An array of found [title, link, matchedKeyword] tuples.
 */
function parseResults(string $html, string $baseUrl, string $keyword, int $limit): array
{
    if (empty($html) || $limit <= 0) {
        return [];
    }

    $results = [];
    $dom = new DOMDocument();

    // Suppress warnings during HTML parsing (especially for malformed HTML)
    libxml_use_internal_errors(true);
    // Ensure UTF-8 encoding is handled correctly by adding meta tag
    // LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD prevents adding implicit <html><body> tags
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors(false); // Restore error handling

    $xpath = new DOMXPath($dom);
    // Prepare keyword for case-insensitive search in XPath 1.0
    $keywordLower = mb_strtolower($keyword, 'UTF-8');
    // Using translate() for case-insensitivity and normalize-space() to handle extra whitespace
    $xpathQuery = sprintf(
        "//a[contains(translate(normalize-space(.), 'ABCDEFGHIJKLMNOPQRSTUVWXYZÇĞİÖŞÜ', 'abcdefghijklmnopqrstuvwxyzçğiöşü'), '%s')]",
        $keywordLower
    );

    $nodes = $xpath->query($xpathQuery);
    $count = 0;

    if ($nodes === false) {
        // Log XPath query error if needed (e.g., error_log("XPath query failed for keyword: $keyword");)
        return [];
    }

    foreach ($nodes as $node) {
        // Ensure node is an element before accessing properties
        if ($node instanceof DOMElement) {
            $title = trim($node->nodeValue);
            $link = trim($node->getAttribute('href'));

            // Skip empty links, anchor links (#), or JavaScript links
            if (empty($link) || str_starts_with($link, '#') || stripos($link, 'javascript:') === 0) {
                continue;
            }

            // Resolve relative URLs more robustly
            if (!preg_match('/^(?:https?:)?\/\//i', $link)) { // Handles http:, https:, //
                 try {
                     // Use a more robust URL joining approach based on PHP's parse_url
                     $baseParts = parse_url($baseUrl);
                     // If base URL parsing fails, fallback or skip? Fallback for now.
                     if ($baseParts === false) throw new Exception("Base URL parsing failed");

                     $linkParts = parse_url($link);
                      // If link parsing fails, treat it as a simple path segment
                     if ($linkParts === false) $linkParts = ['path' => $link];

                     $newUrl = ($baseParts['scheme'] ?? 'http') . '://' . $baseParts['host'];
                     if (isset($baseParts['port'])) {
                         $newUrl .= ':' . $baseParts['port'];
                     }

                     if (str_starts_with($link, '/')) {
                         // Link is absolute path from root
                         $newUrl .= $link;
                     } else {
                         // Link is relative to the current path
                         $basePath = $baseParts['path'] ?? '/';
                         // Ensure base path ends with a slash if it's a directory context
                         if (substr($basePath, -1) !== '/') {
                            $basePath = dirname($basePath) . '/';
                         }
                         $newUrl .= $basePath . $link;
                     }

                    // Basic normalization: resolve /./ and /../ segments - needs improvement for complex cases
                    // This is a simplified approach. A library would be better for full RFC 3986 compliance.
                    $path = parse_url($newUrl, PHP_URL_PATH);
                    $segments = explode('/', $path);
                    $resolvedSegments = [];
                    foreach ($segments as $segment) {
                        if ($segment === '..') {
                            array_pop($resolvedSegments);
                        } elseif ($segment !== '.' && $segment !== '') {
                            $resolvedSegments[] = $segment;
                        }
                    }
                    $resolvedPath = '/' . implode('/', $resolvedSegments);
                    // Reconstruct URL with resolved path
                    $link = preg_replace('/' . preg_quote(parse_url($newUrl, PHP_URL_PATH), '/') . '/', $resolvedPath, $newUrl, 1);


                 } catch (\Exception $e) {
                     // Fallback to simpler join if parse_url fails or other error
                     $link = rtrim($baseUrl, '/') . '/' . ltrim($link, '/');
                     // Log error: error_log("URL resolution failed for '$link' relative to '$baseUrl': " . $e->getMessage());
                 }
            }

            // Ensure we don't add duplicates (based on link)
            $isDuplicate = false;
            foreach ($results as $existingResult) {
                if ($existingResult[1] === $link) {
                    $isDuplicate = true;
                    break;
                }
            }

            if (!$isDuplicate) {
                 // Store title, link, and the keyword that was matched
                 $results[] = [$title, $link, $keyword];
                 $count++;
                 if ($count >= $limit) {
                     break; // Stop once the limit is reached for this keyword
                 }
            }
        }
    }

    return $results;
}

/**
 * Extracts the domain name and base URL from a URL, adding 'http://' if no scheme is present.
 *
 * @param string $url The input URL.
 * @return array{domain: string, baseUrl: string}|false The extracted domain and base URL or false on failure.
 */
function getDomainAndBaseUrl(string $url): array|false
{
    $url = trim($url);
    if (empty($url)) {
        return false;
    }

    // Add http scheme if missing, unless it's clearly schemeless (//) or has another scheme
    if (!preg_match("~^(?:[a-z]+:)?//~i", $url)) {
        $url = "http://" . $url;
    }

    $parsed_url = parse_url($url);

    // Check if parsing failed or host is missing
    if ($parsed_url === false || empty($parsed_url['host'])) {
         // Try harder to find host if path looks like a domain (e.g., example.com/path)
         if (isset($parsed_url['path'])) {
             $pathParts = explode('/', ltrim($parsed_url['path'], '/'), 2); // Remove leading slash before exploding
             // Use filter_var for robust domain validation
             if (!empty($pathParts[0]) && filter_var($pathParts[0], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
                 $host = $pathParts[0];
                 $scheme = $parsed_url['scheme'] ?? 'http'; // Use existing or default scheme
             } else {
                 return false; // Cannot determine host
             }
         } else {
            return false; // Cannot determine host
         }
    } else {
         $host = $parsed_url['host'];
         $scheme = $parsed_url['scheme'] ?? 'http'; // Default scheme if missing (e.g., for //example.com)
    }

    // Remove 'www.' prefix if present for consistency in domain reporting
    $domain = preg_replace('/^www\./i', '', $host);
    // Construct base URL ensuring scheme and host are present
    $baseUrl = $scheme . '://' . $host;
     if (isset($parsed_url['port'])) {
         $baseUrl .= ':' . $parsed_url['port'];
     }

    return ['domain' => $domain, 'baseUrl' => $baseUrl];
}


/**
 * ==================================
 * Main Backend Logic (AJAX Handler)
 * ==================================
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json; charset=utf-8'); // Ensure correct JSON header with UTF-8

    $input = json_decode(file_get_contents('php://input'), true);

    // --- Input Validation ---
    if (!isset($input['domain']) || !is_string($input['domain']) || empty(trim($input['domain']))) {
        http_response_code(400); // Bad Request
        echo json_encode(['error' => 'Invalid or missing domain']);
        exit;
    }

    $domainInfo = getDomainAndBaseUrl($input['domain']);
    if ($domainInfo === false) {
        http_response_code(400);
        echo json_encode(['error' => 'Cannot parse domain: ' . htmlspecialchars($input['domain'])]);
        exit;
    }

    $domain = $domainInfo['domain'];
    $baseUrl = $domainInfo['baseUrl'];

    // Process multiple extra keywords (comma-separated from input)
    $extraKeywords = [];
    if (isset($input['extraKeywords']) && is_string($input['extraKeywords']) && !empty(trim($input['extraKeywords']))) {
        // Split by comma, trim whitespace, filter out empty strings, ensure uniqueness
        $extraKeywords = array_unique(array_filter(array_map('trim', explode(',', $input['extraKeywords']))));
    }

    // --- Prepare Result Structure ---
    $results = [
        'domain' => $domain,
        'baseUrl' => $baseUrl, // Return base URL for potential debugging
        'casinoResults' => [],
        'extraKeywordResults' => [], // Combined results for all extra keywords
        'status' => 'pending', // Will be 'completed' or 'error'
        'error' => null,
        'fetchDetails' => [], // Store details about each fetch attempt
    ];

    $overallError = null; // Track errors across fetches

    // --- 1. Check for "casino" ---
    $searchUrlCasino = $baseUrl . '/?s=casino';
    $fetchResultCasino = fetchData($searchUrlCasino);
    $results['fetchDetails'][] = ['url' => $searchUrlCasino, 'status' => $fetchResultCasino['httpCode'], 'error' => $fetchResultCasino['error']];

    if (!$fetchResultCasino['success']) {
        $overallError = "Casino search failed: " . ($fetchResultCasino['error'] ?? "HTTP {$fetchResultCasino['httpCode']}");
        // Continue to check extra keywords even if casino search fails
    } elseif ($fetchResultCasino['content']) {
        $results['casinoResults'] = parseResults($fetchResultCasino['content'], $baseUrl, 'casino', MAX_CASINO_RESULTS);
    }

    // --- 2. Check for Extra Keywords ---
    $extraResultsCombined = [];
    foreach ($extraKeywords as $keyword) {
        // No need to check for empty keyword here due to array_filter earlier

        $searchUrlExtra = $baseUrl . '/?s=' . urlencode($keyword);
        $fetchResultExtra = fetchData($searchUrlExtra);
        $results['fetchDetails'][] = ['url' => $searchUrlExtra, 'status' => $fetchResultExtra['httpCode'], 'error' => $fetchResultExtra['error']];

        if (!$fetchResultExtra['success']) {
            $errorMsg = "Keyword search ('" . htmlspecialchars($keyword) . "') failed: " . ($fetchResultExtra['error'] ?? "HTTP {$fetchResultExtra['httpCode']}");
            // Append errors using a consistent separator
            $overallError = $overallError ? $overallError . " | " . $errorMsg : $errorMsg;
        } elseif ($fetchResultExtra['content']) {
            $keywordResults = parseResults($fetchResultExtra['content'], $baseUrl, $keyword, MAX_EXTRA_KEYWORD_RESULTS);
            // Add results for this keyword to the combined list
            // array_merge might renumber keys, use loop or + operator if keys matter (they don't here)
            $extraResultsCombined = array_merge($extraResultsCombined, $keywordResults);
        }
    }
    // Store the combined extra keyword results
    $results['extraKeywordResults'] = $extraResultsCombined;


    // --- Finalize Status and Error ---
    if ($overallError) {
        $results['status'] = 'error';
        $results['error'] = $overallError;
        // Optionally set a specific HTTP status code if there were fetch errors
        // http_response_code(503); // Service Unavailable or partial failure
    } else {
        $results['status'] = 'completed';
    }

    // --- Return JSON Response ---
    echo json_encode($results);
    exit; // Stop script execution after handling AJAX request
}

/**
 * ==================================
 * HTML Output (Frontend)
 * ==================================
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Advanced Forbidden Content Checker</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/uikit@3.19.2/dist/css/uikit.min.css" />
    <style>
        /* Custom Styles */
        body { padding: 20px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; background-color: #f9f9f9; }
        .uk-container { background-color: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); max-width: 1200px; } /* Increased max-width */
        .uk-table th { background-color: #f1f3f5; color: #495057; font-weight: 600; text-transform: uppercase; font-size: 0.85rem; } /* Uppercase headers */
        .uk-table td, .uk-table th { vertical-align: middle; padding: 12px 10px; } /* Adjusted padding */
        .result-link { word-break: break-all; } /* Prevent long links from breaking layout */
        .result-details div { margin-bottom: 6px; line-height: 1.4; } /* Spacing and line height */
        .result-details div:last-child { margin-bottom: 0; }
        #resultsContainer { margin-top: 30px; }
        #progressBarContainer { margin-top: 20px; margin-bottom: 10px; }
        #progressBar { height: 12px; border-radius: 6px; background-color: #e9ecef; } /* Custom progress bar background */
        #progressBar::-webkit-progress-bar { background-color: #e9ecef; border-radius: 6px;}
        #progressBar::-webkit-progress-value { background-color: #1e87f0; border-radius: 6px; transition: width 0.3s ease-in-out;}
        #progressBar::-moz-progress-bar { background-color: #1e87f0; border-radius: 6px; transition: width 0.3s ease-in-out;}
        #progressText { margin-top: 8px; font-size: 0.9rem; color: #666; }
        .status-indicator { display: inline-block; width: 14px; height: 14px; border-radius: 50%; margin-right: 6px; vertical-align: middle; } /* Slightly larger indicator */
        .status-yes { background-color: #32d296; box-shadow: 0 0 5px rgba(50, 210, 150, 0.5); } /* Green with subtle glow */
        .status-no { background-color: #adb5bd; } /* Grey for 'no' */
        .status-error { background-color: #f0506e; box-shadow: 0 0 5px rgba(240, 80, 110, 0.5); } /* Red with subtle glow */
        .uk-alert-primary { background-color: #e7f5ff; border-left: 4px solid #1e87f0; color: #052c65; } /* Custom alert style */
        .uk-alert-primary ul { margin-top: 10px; list-style: disc; margin-left: 20px; }
        .uk-alert-primary code { background-color: rgba(30, 135, 240, 0.1); padding: 2px 5px; border-radius: 3px; font-size: 0.85em; color: #052c65;}
        mark { background-color: #ffe066; padding: 0.1em 0.2em; border-radius: 3px; box-shadow: 0 0 3px rgba(255, 224, 102, 0.4); } /* Highlight style with glow */
        #exportButton { display: none; margin-left: 10px; } /* Hide initially, add margin */
        .uk-table td:nth-child(3), .uk-table td:nth-child(5) { max-width: 350px; overflow-wrap: break-word; } /* Increased width for result columns */
        textarea#domains { min-height: 120px; } /* Ensure textarea is reasonably tall */
        .uk-form-label { font-weight: 500; }
        .uk-heading-medium { color: #333; }
        .uk-text-meta { color: #888; font-size: 0.8rem; } /* Style for keyword hint */
        .uk-button-primary { background-color: #1e87f0; }
        .uk-button-secondary { background-color: #6c757d; color: white; }
        .uk-button:disabled { opacity: 0.6; cursor: not-allowed; }
    </style>
</head>
<body>

    <div class="uk-container uk-container-large">

        <h1 class="uk-heading-medium uk-text-center uk-margin-medium-bottom">Advanced Forbidden Content Checker</h1>
        <p class="uk-text-center uk-text-muted uk-margin-bottom">Check WordPress sites for posts containing "casino" and other specified keywords.</p>

        <div class="uk-alert-primary" uk-alert>
            <a href class="uk-alert-close" uk-close></a>
            <p><strong>How it Works:</strong></p>
            <ul>
                <li>Uses the WordPress search function (<code>/?s=keyword</code>) for each entered URL.</li>
                <li>Lists up to <?= MAX_CASINO_RESULTS ?> results for the keyword "casino".</li>
                <li>Lists up to <?= MAX_EXTRA_KEYWORD_RESULTS ?> results *per* additional keyword entered.</li>
                <li>Enter multiple additional keywords separated by commas (e.g., <code>betting, gambling, slot</code>).</li>
                <li>Displays an error message if the site search is unavailable or the site cannot be reached.</li>
                <li>Enter one URL or domain name per line in the text area below.</li>
                <li>Checks are performed concurrently (up to <?= MAX_CONCURRENT_REQUESTS ?> requests at a time) for faster processing.</li>
                <li>You can export the results to a CSV file after the check is complete.</li>
            </ul>
        </div>


        <form id="checkForm" class="uk-form-stacked uk-margin-medium-top">
            <div class="uk-margin">
                <label class="uk-form-label" for="domains">Domains/URLs to Check (one per line):</label>
                <div class="uk-form-controls">
                    <textarea class="uk-textarea" id="domains" name="domains" rows="8" placeholder="example.com&#10;https://www.anothersite.net/news&#10;http://blog.test.org" required spellcheck="false"></textarea>
                </div>
            </div>

            <div class="uk-margin">
                <label class="uk-form-label" for="extraKeywords">Additional Keywords (optional, comma-separated):</label>
                <div class="uk-form-controls">
                    <input class="uk-input" type="text" id="extraKeywords" name="extraKeywords" placeholder="e.g., betting, gambling, slot, poker" spellcheck="false">
                </div>
            </div>

            <div class="uk-margin uk-text-center">
                <button class="uk-button uk-button-primary uk-button-large" type="submit" id="submitButton">
                    <span uk-spinner="ratio: 0.8" id="loadingSpinner" style="display: none; margin-right: 5px;"></span>
                    Start Checking
                </button>
                 <button class="uk-button uk-button-secondary uk-button-large" type="button" id="exportButton">
                    <span uk-icon="download" style="margin-right: 5px;"></span>
                    Export to CSV
                </button>
            </div>
        </form>

        <div id="progressBarContainer" style="display: none;">
            <progress id="progressBar" class="uk-progress" value="0" max="100"></progress>
            <p id="progressText" class="uk-text-center uk-text-small"></p>
        </div>

        <div id="resultsContainer" style="display: none;">
             <h2 class="uk-heading-small uk-margin-top uk-text-center">Results</h2>
            <div class="uk-overflow-auto"> <table class="uk-table uk-table-striped uk-table-hover uk-table-middle uk-table-divider" id="resultsTable">
                    <thead>
                        <tr>
                            <th>Domain</th>
                            <th class="uk-text-center">"Casino" Found</th>
                            <th>"Casino" Results (Max <?= MAX_CASINO_RESULTS ?>)</th>
                            <th class="uk-text-center">Add'l Keywords Found</th>
                            <th>Additional Keyword Results (Max <?= MAX_EXTRA_KEYWORD_RESULTS ?> each)</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="resultBody">
                        </tbody>
                </table>
            </div>
        </div>

    </div><script src="https://cdn.jsdelivr.net/npm/uikit@3.19.2/dist/js/uikit.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/uikit@3.19.2/dist/js/uikit-icons.min.js"></script>

    <script>
        // --- DOM Elements ---
        const checkForm = document.getElementById('checkForm');
        const domainsTextarea = document.getElementById('domains');
        const extraKeywordsInput = document.getElementById('extraKeywords');
        const resultBody = document.getElementById('resultBody');
        const resultsContainer = document.getElementById('resultsContainer');
        const progressBarContainer = document.getElementById('progressBarContainer');
        const progressBar = document.getElementById('progressBar');
        const progressText = document.getElementById('progressText');
        const submitButton = document.getElementById('submitButton');
        const loadingSpinner = document.getElementById('loadingSpinner');
        const exportButton = document.getElementById('exportButton');
        const resultsTable = document.getElementById('resultsTable'); // For CSV export

        // --- Configuration ---
        const MAX_CONCURRENT = <?= MAX_CONCURRENT_REQUESTS ?>; // Get from PHP constant

        // --- Global State ---
        let completedCount = 0;
        let totalCount = 0;
        let allResultsData = []; // Store structured data for CSV export

        // --- Event Listeners ---
        checkForm.addEventListener('submit', handleFormSubmit);
        exportButton.addEventListener('click', handleExportCsv);

        /**
         * Handles the form submission to start the checking process.
         */
        async function handleFormSubmit(event) {
            event.preventDefault(); // Prevent default form submission

            const domains = domainsTextarea.value.trim().split('\n')
                               .map(line => line.trim())
                               .filter(line => line !== ''); // Filter out empty lines
            const extraKeywords = extraKeywordsInput.value.trim(); // Send as comma-separated string

            if (domains.length === 0) {
                showNotification('Please enter at least one domain or URL.', 'warning');
                return;
            }

            resetUI();
            totalCount = domains.length;
            updateProgress(); // Show initial progress (0%)

            // --- Process Domains Concurrently ---
            const results = await processDomainsConcurrently(domains, extraKeywords);

            // --- Finalize UI ---
            // Check if any promise was rejected
            const hasErrors = results.some(result => result.status === 'rejected');
            finalizeUI(hasErrors);
        }

        /**
         * Resets the UI elements before starting a new check.
         */
        function resetUI() {
            resultBody.innerHTML = ''; // Clear previous results
            resultsContainer.style.display = 'none'; // Hide results table
            progressBar.value = 0;
            progressBarContainer.style.display = 'block'; // Show progress bar container
            progressText.textContent = 'Starting checks...'; // Initial progress text
            submitButton.disabled = true; // Disable button during processing
            loadingSpinner.style.display = 'inline-block'; // Show spinner
            exportButton.style.display = 'none'; // Hide export button
            completedCount = 0;
            totalCount = 0;
            allResultsData = []; // Clear data for CSV export
        }

        /**
         * Updates the progress bar and text.
         */
        function updateProgress() {
            const percentage = totalCount > 0 ? Math.round((completedCount / totalCount) * 100) : 0;
            progressBar.value = percentage;
            progressText.textContent = `Checked ${completedCount} of ${totalCount} domains (${percentage}%)`;
            // console.log(`Progress: ${completedCount}/${totalCount}`); // Debug log
        }

        /**
         * Finalizes the UI after all checks are complete.
         * @param {boolean} hasErrors - Indicates if any request failed.
         */
        function finalizeUI(hasErrors) {
            resultsContainer.style.display = 'block'; // Show results table
            submitButton.disabled = false; // Re-enable button
            loadingSpinner.style.display = 'none'; // Hide spinner
            progressText.textContent += " - Complete!";
            if (allResultsData.length > 0) {
                 exportButton.style.display = 'inline-block'; // Show export button only if there are results
            }

            if (hasErrors) {
                 showNotification('Some checks completed with errors. Please review the table for details.', 'warning', 5000);
            } else {
                 showNotification('All checks completed successfully.', 'success', 4000);
            }
        }

        /**
         * Processes the list of domains concurrently with a limit.
         * Manages a queue and a pool of active promises.
         * @param {string[]} domains - Array of domain strings.
         * @param {string} extraKeywords - Comma-separated string of extra keywords.
         * @returns {Promise<PromiseSettledResult<any>[]>} - Array of settled promise results.
         */
        async function processDomainsConcurrently(domains, extraKeywords) {
            const resultsPromises = []; // Store the promises themselves
            const queue = [...domains]; // Create a mutable copy of domains to act as a queue
            const activePromises = new Set(); // Track currently running promises

            // Function to start the next task if concurrency limit allows
            const processNext = () => {
                while (queue.length > 0 && activePromises.size < MAX_CONCURRENT) {
                    const domain = queue.shift(); // Get the next domain from the queue
                    if (!domain) continue; // Should not happen with filter, but safe check

                    // Create the promise for checking this domain
                    const promise = checkSingleDomain(domain, extraKeywords)
                        .then(result => ({ status: 'fulfilled', value: result, domain: domain })) // Wrap result for consistency
                        .catch(error => ({ status: 'rejected', reason: error, domain: domain })) // Wrap error
                        .finally(() => {
                            activePromises.delete(promise); // Remove promise from active set when settled
                            completedCount++;
                            updateProgress(); // Update progress after each completion
                            processNext(); // Attempt to start the next task from the queue
                        });

                    activePromises.add(promise); // Add the new promise to the active set
                    resultsPromises.push(promise); // Add the promise to the list of all promises
                }
            };

            // Start the initial batch of promises
            processNext();

            // Wait for all initiated promises to settle (fulfill or reject)
            // We map resultsPromises because we stored the promises themselves
            return Promise.allSettled(resultsPromises);
        }


       /**
         * Sends a request to the backend to check a single domain.
         * Adds the result row to the table upon completion or failure.
         * @param {string} domain - The domain to check.
         * @param {string} extraKeywords - Comma-separated extra keywords.
         * @returns {Promise<object>} - Promise resolving with the JSON result from the backend on success.
         * @throws {Error} - Throws an error on fetch failure or non-ok HTTP response.
         */
        async function checkSingleDomain(domain, extraKeywords) {
            // console.log(`Checking domain: ${domain}`); // Debug log
            let response; // Declare response outside try block
            try {
                response = await fetch(window.location.href, { // POST to the same PHP script
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest' // Identify as AJAX request
                    },
                    body: JSON.stringify({ domain: domain, extraKeywords: extraKeywords })
                });

                // Try to parse JSON regardless of response.ok, as backend might send error details
                const data = await response.json();

                if (!response.ok) {
                    // Throw an error to be caught locally, including backend error message if available
                    throw new Error(data?.error || `Server error (${response.status})`);
                }

                // Add row for successful request
                addResultRow(data); // data contains the full result object from PHP
                return data; // Resolve with the data

            } catch (error) {
                 // Handle network errors, JSON parsing errors, or errors thrown above
                console.error(`Fetch error for ${domain}:`, error);
                // Add error row to the table
                // Construct a data-like object for addResultRow consistency
                addResultRow({
                    domain: domain, // Use original domain input
                    status: 'error',
                    error: `Request failed: ${error.message}`,
                    casinoResults: [], // Ensure arrays exist for table rendering
                    extraKeywordResults: []
                 });
                throw error; // Re-throw the error so Promise.allSettled captures it as 'rejected'
            }
        }


        /**
         * Adds a result row to the table based on backend response or error object.
         * @param {object} data - The result data object from backend or a custom error object.
         */
        function addResultRow(data) {
            const row = resultBody.insertRow();
            // Ensure data object exists and push a clone if needed for complex objects
            allResultsData.push(JSON.parse(JSON.stringify(data))); // Store data for CSV export

            // Safely access properties, providing defaults
            const domain = data?.domain || 'Unknown Domain';
            const casinoResults = data?.casinoResults || [];
            const extraResults = data?.extraKeywordResults || [];
            const fetchError = data?.error || null;
            const status = data?.status || 'error'; // 'completed' or 'error'

            // --- Create Cells ---
            const cellDomain = row.insertCell();
            const cellCasinoFound = row.insertCell();
            const cellCasinoResults = row.insertCell();
            const cellExtraFound = row.insertCell();
            const cellExtraResults = row.insertCell();
            const cellStatus = row.insertCell();

            // --- Populate Cells ---
            // Domain
            cellDomain.textContent = domain;

            // Casino Found Indicator
            cellCasinoFound.classList.add('uk-text-center');
            // Error state takes precedence if results are empty
            cellCasinoFound.innerHTML = getStatusIndicator(status === 'error' && casinoResults.length === 0, casinoResults.length > 0);

            // Casino Results List
            cellCasinoResults.classList.add('result-details');
            cellCasinoResults.innerHTML = formatResults(casinoResults);

            // Extra Keyword Found Indicator
            cellExtraFound.classList.add('uk-text-center');
            const hasExtraKeywordsInput = extraKeywordsInput.value.trim() !== '';
            // Show error only if keywords were entered but results are empty due to an error
            cellExtraFound.innerHTML = getStatusIndicator(status === 'error' && extraResults.length === 0 && hasExtraKeywordsInput, extraResults.length > 0);


            // Extra Keyword Results List
            cellExtraResults.classList.add('result-details');
            cellExtraResults.innerHTML = formatResults(extraResults);

            // Status Text
            if (status === 'error') {
                // Provide a more user-friendly default error message
                const displayError = fetchError || 'An unknown error occurred';
                cellStatus.innerHTML = `<span class="uk-text-danger" title="${displayError}">Error</span>`;
                row.classList.add('uk-background-muted'); // Optional: Highlight error rows
            } else {
                 cellStatus.innerHTML = '<span class="uk-text-success">Success</span>';
            }
        }

        /**
         * Generates the HTML for the status indicator icon (Yes/No/Error).
         * @param {boolean} isErrorState - If checking failed and no results were found.
         * @param {boolean} isFound - If items were successfully found.
         * @returns {string} HTML string for the indicator.
         */
        function getStatusIndicator(isErrorState, isFound) {
             if (isErrorState) {
                // Indicates checking might have failed or was inconclusive
                return '<span class="status-indicator status-error" title="Check Failed / Error"></span>';
            } else if (isFound) {
                // Items were successfully found
                return '<span class="status-indicator status-yes" title="Found"></span>';
            } else {
                // Checking was successful, but no items were found
                return '<span class="status-indicator status-no" title="Not Found"></span>';
            }
        }


        /**
         * Formats an array of [title, link, matchedKeyword] tuples into an HTML string.
         * Highlights the matched keyword in the title.
         * @param {Array<Array<string>>} results - Array of [title, link, matchedKeyword].
         * @returns {string} HTML string.
         */
        function formatResults(results) {
            if (!results || results.length === 0) return '<span class="uk-text-muted">-</span>';

            // Function to escape regex special characters for safe use in RegExp
            const escapeRegex = (s) => s.replace(/[-\/\\^$*+?.()|[\]{}]/g, '\\$&');

            return results.map(([title, link, matchedKeyword]) => {
                let highlightedTitle = title || 'No Title Found'; // Provide default if title is empty
                // Highlight the keyword (case-insensitive) only if matchedKeyword is present
                if (matchedKeyword) {
                    try {
                        // Create a case-insensitive regex for the matched keyword
                        const regex = new RegExp(`(${escapeRegex(matchedKeyword)})`, 'gi');
                         highlightedTitle = highlightedTitle.replace(regex, '<mark>$1</mark>');
                    } catch (e) {
                        console.warn("Regex error during highlighting:", e);
                        // Fallback: no highlighting if regex fails
                    }
                }

                // Basic sanitization/encoding for the link in href attribute
                const safeLink = link ? encodeURI(link) : '#'; // Use '#' for empty links

                return `<div>
                            <a href="${safeLink}" target="_blank" rel="noopener noreferrer" class="uk-link-text result-link" title="Open link: ${link || ''}">
                                ${highlightedTitle}
                            </a>
                            ${matchedKeyword ? `<span class="uk-text-meta uk-display-block">(Keyword: ${matchedKeyword})</span>` : ''}
                         </div>`;
            }).join('');
        }

        /**
         * Shows a UIkit notification.
         * @param {string} message - The message to display.
         * @param {string} status - Notification status ('primary', 'success', 'warning', 'danger').
         * @param {number} [timeout=3000] - Duration in milliseconds.
         */
        function showNotification(message, status, timeout = 3000) {
            UIkit.notification({ message: message, status: status, pos: 'top-center', timeout: timeout });
        }

        // --- CSV Export Functionality ---

        /**
         * Handles the click event for the Export CSV button.
         */
        function handleExportCsv() {
            if (allResultsData.length === 0) {
                showNotification('No data available to export.', 'warning');
                return;
            }
            try {
                const csvContent = generateCsvContent(allResultsData);
                downloadCsv(csvContent, 'forbidden_content_results.csv');
            } catch (error) {
                 console.error("Error generating or downloading CSV:", error);
                 showNotification('Failed to export results to CSV.', 'danger');
            }
        }

        /**
         * Generates CSV content string from the results data.
         * @param {Array<object>} data - The array of result objects.
         * @returns {string} - The CSV formatted string.
         */
        function generateCsvContent(data) {
            // Define clear CSV headers
            const header = [
                "Input Domain/URL", // Changed from "Domain" to be more precise
                "Base URL Checked", // Changed from "Base URL"
                "Casino Found",
                "Casino Results (Title | Link)",
                "Add'l Keywords Found",
                "Add'l Keyword Results (Title | Link | Keyword)",
                "Overall Status", // Changed from "Status"
                "Error Details"
            ];
            // Use Unicode Byte Order Mark (BOM) for better Excel compatibility with UTF-8
            const BOM = "\uFEFF";
            const rows = [header.map(escapeCsvCell).join(',')]; // Add header row

            data.forEach(item => {
                // Consolidate result formatting logic
                const formatResultText = (results) => (results || [])
                    .map(([title, link, keyword]) => {
                        const cleanTitle = (title || '').replace(/[\r\n",]+/g, ' '); // Remove newlines, quotes, commas from title
                        const keywordPart = keyword ? ` | ${keyword}` : ''; // Add keyword only if present
                        return `${cleanTitle} | ${link || ''}${keywordPart}`;
                    })
                    .join('; '); // Use semicolon to separate multiple results within a cell

                const casinoResultsText = formatResultText(item.casinoResults);
                const extraResultsText = formatResultText(item.extraKeywordResults);

                const row = [
                    item.domain || '', // Input domain might differ slightly from base URL
                    item.baseUrl || '',
                    (item.casinoResults || []).length > 0 ? 'Yes' : 'No',
                    casinoResultsText,
                    (item.extraKeywordResults || []).length > 0 ? 'Yes' : 'No',
                    extraResultsText,
                    item.status === 'completed' ? 'Success' : 'Error', // Use more descriptive status
                    (item.error || '').replace(/[\r\n",]+/g, ' ') // Clean error message for CSV
                ];
                rows.push(row.map(escapeCsvCell).join(','));
            });

            return BOM + rows.join('\n'); // Prepend BOM and join rows
        }

        /**
         * Escapes a string for use in a CSV cell (handles commas, quotes, newlines).
         * Ensures data is treated as text.
         * @param {string|number|boolean|null|undefined} cellData - Data for the cell.
         * @returns {string} - Escaped CSV cell string.
         */
        function escapeCsvCell(cellData) {
            const str = String(cellData ?? ''); // Convert to string, handle null/undefined safely

            // If the string contains a comma, double quote, or newline, enclose in double quotes
            if (/[",\n\r]/.test(str)) {
                // Escape existing double quotes by doubling them (e.g., " becomes "")
                const escapedStr = str.replace(/"/g, '""');
                return `"${escapedStr}"`;
            }
            // No special characters, return as is
            return str;
        }

        /**
         * Triggers the download of the CSV content.
         * @param {string} csvContent - The CSV string.
         * @param {string} filename - The desired filename for the download.
         */
        function downloadCsv(csvContent, filename) {
            // Use Blob with correct MIME type and charset
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement("a");

            // Use download attribute for modern browsers
            if (link.download !== undefined) {
                const url = URL.createObjectURL(blob);
                link.setAttribute("href", url);
                link.setAttribute("download", filename);
                link.style.visibility = 'hidden';
                document.body.appendChild(link);
                link.click(); // Programmatically click the link to trigger download
                document.body.removeChild(link); // Clean up the link element
                URL.revokeObjectURL(url); // Release the object URL
            } else {
                 // Fallback for older browsers (might open in new tab/window)
                 showNotification('Your browser may not support automatic downloads. Please check your downloads folder or try a different browser.', 'warning', 6000);
                 // As a basic fallback, try opening the data URI (limited support)
                 // window.open('data:text/csv;charset=utf-8,' + encodeURIComponent(csvContent));
            }
        }

    </script>

</body>
</html>
