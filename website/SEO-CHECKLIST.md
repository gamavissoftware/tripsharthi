# TripSarthi — SEO go-live checklist

**Be realistic:** nobody can promise a ranking, and a brand-new domain normally takes weeks to months to appear for competitive searches. What the site already does is remove every *technical* obstacle (Lighthouse on a throttled phone: SEO 100, Accessibility 100, Best Practices 100, Performance 95+), and give search engines real content to rank. The rest below is what only you can do — it is what actually moves rankings.

## Already built into the site
- One H1 and a unique title and description on every page; canonical URLs; `en-IN` language and hreflang; Open Graph / Twitter cards with a 1200×630 share image
- Structured data: Organization (address, phones, email), WebSite, SoftwareApplication with the three plans, FAQPage on every page that has an FAQ, BreadcrumbList on inner pages, Article on blog posts
- `sitemap.xml` (with `lastmod`), `robots.txt`, `llms.txt` (a plain-text summary for AI search tools), web manifest, a custom 404 (noindex)
- Content: 4 solution pages for the searches Indian agencies actually make (travel agency software, WhatsApp CRM, GST billing, tour operator software) and 3 practical guides
- Speed: self-hosted fonts, one minified stylesheet (inlined on the home page), responsive WebP images with `srcset`, lazy loading, versioned CSS/JS for long caching, security headers (`_headers`, `deploy/nginx-website.conf.example`)
- The product app (`app.tripsarthi.com`) is `noindex`, so it never competes with the marketing site

## Before launch (day 0)
1. Serve the site over **HTTPS** on `https://tripsarthi.com` and redirect `www` and `http` to it (the nginx example does this). The canonical tags assume exactly this host.
2. Upload `website/` and open `/sitemap.xml`, `/robots.txt`, `/llms.txt` in a browser to check they load.
3. Add your real **logo, address, phone and email** everywhere identically (name, address, phone = "NAP"). They live at the top of `website/build.py`; run `python3 website/build.py` after editing.
4. Have a lawyer review `privacy.html` and `terms.html`.

## Week 1
5. **Google Search Console** → add the *Domain* property for `tripsarthi.com` (DNS TXT verification, or paste the HTML-tag token into `GSC_TOKEN` in `build.py`, rebuild, upload). Submit `https://tripsarthi.com/sitemap.xml`. Use *URL inspection → Request indexing* for the home page and the four solution pages.
6. **Bing Webmaster Tools** → "Import from Google Search Console" (one click). Bing also feeds several other search engines and AI assistants.
7. **Google Business Profile** (free, important for "near me" and brand searches): category *Software company*, the Faridabad address, the three phone numbers, website, opening hours, logo and a few photos. Verify it (postcard / phone / video). Ask happy customers for reviews once you have them.
8. Create the same-name profiles on **LinkedIn** (company page), **YouTube** (short product demos — search engines love video), Instagram and X. Link each back to the website, and add their URLs to the Organization `sameAs` list in `build.py`.

## Month 1–3 (this is what ranks you)
9. **Backlinks:** get listed on software directories (G2, Capterra / GetApp, SoftwareSuggest, TrustRadius, Product Hunt) and Indian business directories; reach out to travel-trade associations and travel bloggers; offer guest guides to travel-trade publications. A few relevant links beat many random ones.
10. **Publish regularly:** 2 useful posts a month from the keyword list below, each with real examples and screenshots. Update older posts when rules change (GST/TCS especially) and bump the date.
11. **Customer proof:** with permission, add case studies and testimonials, and Review markup — never publish invented reviews or ratings (Google penalises it).
12. **Local pages (optional):** "Travel agency software in Delhi NCR / Mumbai / Pune …" only if each page has genuinely different, useful content.
13. **Measure:** Search Console → Performance (queries, pages, clicks), Pages (indexing problems) and Core Web Vitals. Add analytics only with a consent notice if you use cookies (the site currently sets none).

## Keyword ideas (India)
travel agency software · travel agent CRM · WhatsApp CRM for travel agents · travel CRM India · tour operator software · itinerary builder software · travel quotation software · GST invoice software for travel agents · TCS on tour packages · group tour management software · DMC software · Click-to-WhatsApp ads for travel agents · travel agency billing software

## Monthly routine
- Re-run Lighthouse (`npx lighthouse https://tripsarthi.com --view`) and fix regressions.
- Check Search Console for indexing errors; fix broken links; refresh one old post.
- Validate structured data at <https://search.google.com/test/rich-results>.
