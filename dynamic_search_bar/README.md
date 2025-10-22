# Dynamic Search Bar (JTL-Shop 5.6.0)

Bu eklenti, JTL 5.x 'info.xml' formatına göre hazırlanmıştır.


## Kullanım
Sayfanızda bir input ekleyin:

```html
<input
  id="dynamic-search-input"
  placeholder="Suchen..."
  data-label-product="Produkte"
  data-label-category="Kategorien"
  data-label-manufacturer="Hersteller"
  data-label-popular="Beliebte Suchen"
  data-min="4"
/> 
```

JS otomatik olarak `/index.php?dsb_search=1` endpoint'ine sorgu gönderir; gerekirse `io.php` fallback'i devreye girer.


## Ayarlar (Admin)
- dsb_enable_products/categories/manufacturers: Y/N
- dsb_min_chars: minimum karakter (varsayılan 4)
- limit_*: her tür için limitler
- Das Suchprotokoll (`xplugin_dsb_search_log`) wird automatisch erstellt und hält populäre Suchbegriffe vor.
- Über `/index.php?dsb_api=1&action=popular` können beliebte Suchen abgefragt werden.

## Templating
```html
<input id="dynamic-search-input" data-min="4" placeholder="Ara..." />
```
