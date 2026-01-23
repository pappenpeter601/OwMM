<?php
/**
 * Dynamic Sitemap Generator for Google Search Console
 * Generates XML sitemap from actual content and page structure
 */

require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/functions.php';

// Set XML header
header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=86400'); // Cache for 24 hours

// Get base URL from config
$base_url = get_org_setting('site_url');

// Define all public pages with priority and change frequency
$pages = [
    // Main pages
    ['url' => 'index.php', 'lastmod' => date('Y-m-d'), 'changefreq' => 'weekly', 'priority' => '1.0'],
    
    // Public pages
    ['url' => 'board.php', 'lastmod' => date('Y-m-d'), 'changefreq' => 'monthly', 'priority' => '0.8'],
    ['url' => 'contact.php', 'lastmod' => date('Y-m-d'), 'changefreq' => 'monthly', 'priority' => '0.7'],
    ['url' => 'events.php', 'lastmod' => date('Y-m-d'), 'changefreq' => 'weekly', 'priority' => '0.8'],
    ['url' => 'operations.php', 'lastmod' => date('Y-m-d'), 'changefreq' => 'weekly', 'priority' => '0.8'],
    ['url' => 'trucks.php', 'lastmod' => date('Y-m-d'), 'changefreq' => 'monthly', 'priority' => '0.7'],
    
    // Legal pages
    ['url' => 'impressum.php', 'lastmod' => date('Y-m-d'), 'changefreq' => 'yearly', 'priority' => '0.4'],
    ['url' => 'datenschutz.php', 'lastmod' => date('Y-m-d'), 'changefreq' => 'yearly', 'priority' => '0.4'],
    
    // Authentication (public forms)
    ['url' => 'register.php', 'lastmod' => date('Y-m-d'), 'changefreq' => 'monthly', 'priority' => '0.6'],
    ['url' => 'request_magiclink.php', 'lastmod' => date('Y-m-d'), 'changefreq' => 'yearly', 'priority' => '0.5'],
];

// Output XML sitemap
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
echo '        xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"' . "\n";
echo '        xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9' . "\n";
echo '        http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd">' . "\n";

// Add all pages
foreach ($pages as $page) {
    $url = $base_url . '/' . ltrim($page['url'], '/');
    echo "  <url>\n";
    echo "    <loc>" . htmlspecialchars($url, ENT_XML1, 'UTF-8') . "</loc>\n";
    echo "    <lastmod>" . $page['lastmod'] . "</lastmod>\n";
    echo "    <changefreq>" . $page['changefreq'] . "</changefreq>\n";
    echo "    <priority>" . $page['priority'] . "</priority>\n";
    echo "  </url>\n";
}

echo '</urlset>';
?>
