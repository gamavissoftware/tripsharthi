// Mirrors backend/app/Services/Travel/Currency.php (minor-unit sizes and symbols). INR is the only accounting currency.
export const CUR = {
  USD: ['US Dollar', '$', 2], EUR: ['Euro', '€', 2], GBP: ['Pound Sterling', '£', 2], AED: ['UAE Dirham', 'AED ', 2], SGD: ['Singapore Dollar', 'S$', 2], THB: ['Thai Baht', '฿', 2],
  MYR: ['Malaysian Ringgit', 'RM', 2], IDR: ['Indonesian Rupiah', 'Rp', 2], JPY: ['Japanese Yen', '¥', 0], AUD: ['Australian Dollar', 'A$', 2], NZD: ['New Zealand Dollar', 'NZ$', 2],
  CAD: ['Canadian Dollar', 'C$', 2], CHF: ['Swiss Franc', 'CHF ', 2], HKD: ['Hong Kong Dollar', 'HK$', 2], CNY: ['Chinese Yuan', 'CN¥', 2], KRW: ['South Korean Won', '₩', 0],
  VND: ['Vietnamese Dong', '₫', 0], LKR: ['Sri Lankan Rupee', 'Rs ', 2], NPR: ['Nepalese Rupee', 'NRs ', 2], BDT: ['Bangladeshi Taka', '৳', 2], MVR: ['Maldivian Rufiyaa', 'MVR ', 2],
  MUR: ['Mauritian Rupee', 'MUR ', 2], SAR: ['Saudi Riyal', 'SAR ', 2], QAR: ['Qatari Riyal', 'QAR ', 2], OMR: ['Omani Rial', 'OMR ', 3], KWD: ['Kuwaiti Dinar', 'KWD ', 3],
  BHD: ['Bahraini Dinar', 'BHD ', 3], TRY: ['Turkish Lira', '₺', 2], EGP: ['Egyptian Pound', 'E£', 2], ZAR: ['South African Rand', 'R', 2], KES: ['Kenyan Shilling', 'KSh ', 2], BTN: ['Bhutanese Ngultrum', 'Nu ', 2],
}
export const exp = (c) => CUR[c]?.[2] ?? 2
export const toMinor = (major, c) => Math.round(Number(major || 0) * 10 ** exp(c))
export const toMajor = (minor, c) => Number(minor || 0) / 10 ** exp(c)
export const fmt = (minor, c) => (CUR[c]?.[1] ?? `${c} `) + toMajor(minor, c).toLocaleString('en-US', { minimumFractionDigits: exp(c), maximumFractionDigits: exp(c) })
/** Foreign cost -> INR paise at a cost rate (market + buffer already included). */
export const toInrPaise = (minor, c, costRate) => Math.round(toMajor(minor, c) * Number(costRate) * 100)
