# Technical Implementation Details

## Files Overview

### 1. sitemap.php
**Purpose**: Dynamically generates XML sitemap for Google Search Console

**Features**:
- Loads from config.php for SITE_URL
- Returns proper XML header with UTF-8 encoding
- Sets 24-hour cache headers
- Includes all public pages with priority and change frequency
- Protected pages are intentionally excluded

**Usage**:
- Automatically generated on each request (cached for 24 hours)
- No database queries - purely file-based
- Complies with Sitemap Protocol v0.9

**Accessible at**: https://owmm.de/sitemap.php

### 2. robots.txt
**Purpose**: Instructs search engines on how to crawl the site

**Key Rules**:
```
User-agent: *              # Applies to all crawlers
Allow: /                   # Allow general crawling
Disallow: /admin/          # Block private admin area
Disallow: /config/         # Block config files
Disallow: /database/       # Block database files
Disallow: /includes/       # Block PHP includes
Disallow: /.git/           # Block git directory
Disallow: /.config/        # Block hidden config
Disallow: /logs/           # Block log files
Crawl-delay: 5             # Respect server with 5s delays
Sitemap: https://owmm.de/sitemap.php  # Sitemap location
```

**Accessible at**: https://owmm.de/robots.txt

### 3. includes/header.php
**Enhanced with**:

#### Meta Tags
```php
<title>Page Title | Site Name</title>
<meta name="description" content="...">
<meta name="keywords" content="...">
<meta name="robots" content="index, follow">
<meta name="language" content="de">
<meta name="revisit-after" content="7 days">
```

#### Open Graph (Social Media)
```php
<meta property="og:type" content="website">
<meta property="og:url" content="...">
<meta property="og:title" content="...">
<meta property="og:description" content="...">
<meta property="og:locale" content="de_DE">
```

#### Structured Data (JSON-LD)
```json
{
  "@context": "https://schema.org",
  "@type": "Organization",
  "name": "Feuerwehr Meinern-Mittelstendorf",
  "url": "https://owmm.de",
  "contactPoint": {...},
  "areaServed": {...}
}
```

#### Canonical URLs
```php
<link rel="canonical" href="https://owmm.de/page.php">
```

## SEO Configuration

### Sitemap Priority
| Page | Priority | Change Freq | Reason |
|------|----------|-------------|--------|
| index.php | 1.0 | weekly | Homepage - most important |
| events.php | 0.8 | weekly | Frequent updates |
| operations.php | 0.8 | weekly | Frequent updates |
| board.php | 0.8 | monthly | Leadership info |
| trucks.php | 0.7 | monthly | Vehicle info |
| contact.php | 0.7 | monthly | Contact form |
| register.php | 0.6 | monthly | Registration |
| request_magiclink.php | 0.5 | yearly | Rarely changes |
| impressum.php | 0.4 | yearly | Legal page |
| datenschutz.php | 0.4 | yearly | Privacy policy |

### Blocked Pages (Not in Sitemap)
- `/admin/*` - Private dashboard
- `/config/*` - Configuration
- `/database/*` - Database files
- `/includes/*` - PHP includes
- `verify_magiclink.php` - Single-use verification
- `verify_registration.php` - Single-use verification
- `/.git/*` - Version control

## Google Search Console Verification

### Via DNS (Recommended)
1. In Search Console, select DNS verification
2. Copy the TXT record value
3. Add to domain's DNS settings:
   ```
   owmm-xxxxx.acm-validations.aws. 300 IN TXT "..."
   ```
4. Google verifies ownership

### Via HTML File
1. Download HTML verification file
2. Upload to: `https://owmm.de/google-xyz.html`
3. Google verifies

### Via HTML Meta Tag
1. Copy meta tag from Search Console
2. Add to `includes/header.php` in `<head>`
3. Google verifies

## Performance Considerations

### Sitemap Caching
```php
header('Cache-Control: public, max-age=86400'); // 24 hours
```
- Reduces server load
- Search engines respect cache headers
- New content takes up to 24 hours to appear

### robots.txt Caching
- Usually cached 24-48 hours
- Check robots.txt at least weekly
- Update takes 24-48 hours to propagate

## Monitoring & Maintenance

### Weekly Tasks
- [ ] Check Search Console Coverage report
- [ ] Review Performance metrics
- [ ] Look for new crawl errors
- [ ] Check CTR and rankings

### Monthly Tasks
- [ ] Update priority/frequency if content patterns change
- [ ] Check Core Web Vitals
- [ ] Review backlink profile
- [ ] Test page speed

### As Needed
- [ ] Add new pages to sitemap
- [ ] Update meta descriptions
- [ ] Fix crawl errors
- [ ] Request re-indexing of important pages

## Common Issues & Solutions

### Issue: Sitemap returns HTML instead of XML
**Solution**: Check that PHP is installed and configured correctly
```bash
php -l sitemap.php  # Verify syntax
```

### Issue: Pages not indexing
**Solution**: Check robots.txt and meta tags
```bash
curl https://owmm.de/robots.txt | grep "Disallow"
curl https://owmm.de/ | grep "meta name=\"robots\""
```

### Issue: Low rankings
**Solution**: Improve content quality, build backlinks, ensure freshness

### Issue: Duplicate content warnings
**Solution**: Verify canonical URLs are correct
```bash
curl https://owmm.de/page.php | grep "rel=\"canonical\""
```

## SEO Best Practices Applied

✅ **XML Sitemap** - Helps Google discover all pages  
✅ **robots.txt** - Controls crawler behavior  
✅ **Meta Tags** - Improves SERP snippets  
✅ **Canonical URLs** - Prevents duplicate content  
✅ **Structured Data** - Rich snippets for search results  
✅ **Mobile Friendly** - Responsive design  
✅ **HTTPS** - Secure connection (assumed)  
✅ **Fast Loading** - Optimized assets  
✅ **Fresh Content** - Regular updates recommended  

## Validation Tools

| Tool | URL | Purpose |
|------|-----|---------|
| W3C Validator | https://validator.w3.org/ | Validate HTML syntax |
| Schema Validator | https://schema.org/validator/ | Validate structured data |
| Mobile Test | https://search.google.com/test/mobile-friendly | Test mobile |
| PageSpeed | https://pagespeed.web.dev/ | Performance analysis |
| Lighthouse | Built into Chrome DevTools | Comprehensive audit |

---

**Technical Questions?** Check the full setup guide in GOOGLE_SEARCH_CONSOLE_SETUP.md
