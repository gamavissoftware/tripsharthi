<?php /** Shared print stylesheet. dompdf: tables for layout, no flex/grid. $brand['color'] is validated #rrggbb by BusinessProfileService. */
$c = $brand['color'] ?? '#0a6cc4'; ?>
<style>
  @page { margin: 34px 40px 46px 40px; }
  * { box-sizing: border-box; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.8pt; color: #1f2430; line-height: 1.42; margin: 0; }
  h1, h2, h3, h4, p { margin: 0; }
  table { border-collapse: collapse; width: 100%; }
  td, th { vertical-align: top; }
  .muted { color: #6b7280; } .small { font-size: 7.6pt; } .tiny { font-size: 6.8pt; } .right { text-align: right; } .center { text-align: center; } .bold { font-weight: bold; }
  .brand { color: <?= $c ?>; }
  .rule { border-top: 1.5px solid <?= $c ?>; height: 0; margin: 8px 0 10px; }
  .hair { border-top: 1px solid #e5e7eb; height: 0; margin: 8px 0; }
  .eyebrow { font-size: 7pt; letter-spacing: 1.6px; text-transform: uppercase; color: #6b7280; font-weight: bold; }
  .title { font-size: 22pt; font-weight: bold; line-height: 1.12; color: #111827; }
  .h2 { font-size: 12pt; font-weight: bold; color: #111827; margin: 16px 0 6px; padding-bottom: 4px; border-bottom: 1.5px solid <?= $c ?>; }
  .card { border: 1px solid #e5e7eb; padding: 10px 12px; background: #fafafb; }
  .hero { width: 100%; height: 200px; }
  .pill { display: inline-block; background: #eef2ff; color: <?= $c ?>; font-size: 7pt; font-weight: bold; padding: 1px 6px; letter-spacing: .6px; }
  .tag { display: inline-block; font-size: 6.2pt; font-weight: bold; letter-spacing: .8px; padding: 1px 5px; color: #fff; background: <?= $c ?>; }
  .day { page-break-inside: avoid; margin-bottom: 9px; border: 1px solid #e5e7eb; }
  .daynum { background: <?= $c ?>; color: #fff; text-align: center; width: 54px; padding: 8px 4px; }
  .daynum .n { font-size: 17pt; font-weight: bold; line-height: 1; } .daynum .l { font-size: 6.2pt; letter-spacing: 1.4px; }
  .daybody { padding: 8px 12px; }
  .item td { padding: 2.5px 0; border-top: 1px dotted #e5e7eb; }
  .price td { padding: 5px 8px; border-bottom: 1px solid #eef0f3; }
  .total td { padding: 8px; font-weight: bold; font-size: 11pt; background: <?= $c ?>; color: #fff; }
  .grid th { background: #f3f4f6; color: #374151; font-size: 7.2pt; text-transform: uppercase; letter-spacing: .6px; padding: 5px 7px; text-align: left; border: 1px solid #e5e7eb; }
  .grid td { padding: 5px 7px; border: 1px solid #e5e7eb; }
  .twocol td { width: 50%; padding: 0 8px 0 0; }
  ul.tick { margin: 0; padding: 0; list-style: none; } ul.tick li { padding: 2px 0 2px 14px; position: relative; }
  .stamp { border: 2px solid #b91c1c; color: #b91c1c; font-weight: bold; padding: 2px 8px; font-size: 9pt; letter-spacing: 1px; }
</style>
