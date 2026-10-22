import { Platform } from 'react-native'
import * as SecureStore from 'expo-secure-store'

/**
 * Language: English or Hindi. Like the colour theme it is chosen when the app starts (stored in the secure store and read synchronously),
 * so a change applies the next time the app is opened. `tr('English text')` returns the Hindi words when we have them and the English text
 * otherwise, so a missing translation can never show a blank or a key. Shared components (buttons, section titles, labels, tabs) translate
 * themselves, which covers the main screens; long customer-specific sentences and WhatsApp messages are shown as they are.
 */
export const LANG_KEY = 'tp_lang'
function saved() {
  try {
    if (Platform.OS === 'web') return typeof localStorage !== 'undefined' ? localStorage.getItem(LANG_KEY) : null
    return SecureStore.getItem(LANG_KEY)
  } catch { return null }
}
export const lang = saved() === 'hi' ? 'hi' : 'en'
export function saveLang(v) {
  if (Platform.OS === 'web') { try { localStorage.setItem(LANG_KEY, v) } catch { /* ignore */ } return }
  try { SecureStore.setItem(LANG_KEY, v) } catch { /* ignore */ }
}

const HI = {
  // tabs and screens
  'Home': 'होम', 'Enquiries': 'पूछताछ', 'Chats': 'चैट', 'WhatsApp chats': 'व्हाट्सऐप चैट', 'Bookings': 'बुकिंग', 'More': 'और',
  'Enquiry': 'पूछताछ', 'Booking': 'बुकिंग', 'Contact': 'संपर्क', 'Chat': 'चैट', 'Quote': 'कोटेशन', 'New enquiry': 'नई पूछताछ',
  'Tasks': 'कार्य', 'Contacts': 'संपर्क', 'Group departures': 'ग्रुप टूर', 'Sales targets': 'बिक्री लक्ष्य', 'Business report': 'व्यापार रिपोर्ट',
  'Notifications': 'सूचनाएं', 'Settings': 'सेटिंग्स', 'All features': 'सभी सुविधाएं', 'Channels & settings': 'चैनल और सेटिंग्स', 'Account': 'खाता',
  'TripSarthi admin': 'ट्रिपसारथी एडमिन', 'Everything in TripSarthi': 'ट्रिपसारथी की हर सुविधा', 'App settings': 'ऐप सेटिंग्स',
  // buttons and actions
  'Add task': 'कार्य जोड़ें', 'Add a task': 'कार्य जोड़ें', 'Add a follow-up task': 'फॉलो-अप कार्य जोड़ें', 'Create enquiry': 'पूछताछ बनाएं',
  'Save contact': 'संपर्क सहेजें', 'New contact': 'नया संपर्क', 'New task': 'नया कार्य', 'Retry': 'फिर कोशिश करें', 'Try again': 'फिर कोशिश करें',
  'Remind': 'याद दिलाएं', 'Reopen': 'दोबारा खोलें', 'Link': 'लिंक', 'Mark as paid': 'भुगतान हुआ', 'Mark as resolved': 'हल हुआ मानें',
  'Record a payment': 'भुगतान दर्ज करें', 'Send quote': 'कोटेशन भेजें', 'Send quote on WhatsApp': 'व्हाट्सऐप पर कोटेशन भेजें',
  'Send an approved template': 'स्वीकृत टेम्पलेट भेजें', 'Send template': 'टेम्पलेट भेजें', 'Send a file': 'फ़ाइल भेजें', 'Take a photo': 'फोटो खींचें',
  'Photo or video': 'फोटो या वीडियो', 'Document': 'दस्तावेज़', 'Update': 'अपडेट', 'Update price': 'कीमत अपडेट करें', 'Get the new version': 'नया संस्करण लें',
  'Assign to me': 'मुझे सौंपें', 'Unassign from me': 'मुझसे हटाएं', 'Send me a test': 'मुझे टेस्ट भेजें', 'Turn on notifications': 'सूचनाएं चालू करें',
  'Choose another': 'दूसरा चुनें', 'Open contact': 'संपर्क खोलें', 'Open the contact': 'संपर्क खोलें', 'Draft a quote with AI': 'AI से कोटेशन बनाएं',
  'Sign out': 'साइन आउट', 'Sign in': 'साइन इन', 'Cancel': 'रद्द करें', 'Save': 'सहेजें', 'Close': 'बंद करें', 'Done': 'हो गया', 'Open': 'खुले',
  'Go to my workspace': 'मेरे वर्कस्पेस पर जाएं', 'Sign out of admin': 'एडमिन से साइन आउट', 'View / edit': 'देखें / बदलें',
  // fields
  'Customer name': 'ग्राहक का नाम', 'Name': 'नाम', 'Mobile / WhatsApp number': 'मोबाइल / व्हाट्सऐप नंबर', 'E-mail (optional)': 'ई-मेल (वैकल्पिक)',
  'Destination': 'गंतव्य', 'Travel date': 'यात्रा की तारीख', 'Nights': 'रातें', 'Adults': 'वयस्क', 'Children': 'बच्चे', 'Budget ₹': 'बजट ₹', 'Notes': 'टिप्पणी',
  'What needs doing?': 'क्या करना है?', 'Type': 'प्रकार', 'When': 'कब', 'Or pick a date': 'या तारीख चुनें', 'Reference / UTR (optional)': 'रेफरेंस / UTR (वैकल्पिक)',
  'Markup %': 'मार्कअप %', 'Discount ₹': 'छूट ₹', 'Admin password': 'एडमिन पासवर्ड', 'Who is travelling?': 'कौन यात्रा कर रहा है?', 'Where and when?': 'कहां और कब?',
  // statuses and words
  'All': 'सभी', 'Open trips': 'खुली पूछताछ', 'Booked': 'बुक हुई', 'Quoted': 'कोटेशन भेजा', 'Paid': 'चुकाया', 'Overdue': 'बकाया', 'Due': 'देय', 'Unread': 'अपठित',
  'Opted in': 'सहमति दी', 'Total': 'कुल', 'Collected': 'वसूला', 'Outstanding': 'बाकी', 'Customer': 'ग्राहक', 'Everyone': 'सब', 'Whole team': 'पूरी टीम',
  'Revenue': 'आय', 'Revenue (ex-tax)': 'आय (कर के बिना)', 'Margin': 'मार्जिन', 'Forecast': 'अनुमान', 'Pipeline': 'पाइपलाइन', 'Business': 'व्यापार',
  'Cash collected': 'वसूली गई नकदी', 'Cash position': 'नकदी की स्थिति', 'Net position': 'शुद्ध स्थिति', 'Owed to suppliers': 'सप्लायर को देय', 'You owe suppliers': 'सप्लायर का बकाया',
  'To collect': 'वसूलना है', 'Payments to collect': 'वसूलने वाले भुगतान', 'Overdue from customers': 'ग्राहकों से बकाया', 'Collections in 30 days': '30 दिन में वसूली',
  'Needs your attention': 'आपका ध्यान चाहिए', 'Your work': 'आपका काम', 'Coming up from customers': 'ग्राहकों से आने वाला', 'Quotes to chase': 'पीछा करने वाले कोटेशन',
  'Quotes sent': 'भेजे गए कोटेशन', 'Open quotes': 'खुले कोटेशन', 'Lead sources': 'लीड के स्रोत', 'Where enquiries come from': 'पूछताछ कहां से आती है',
  'Top destinations': 'लोकप्रिय गंतव्य', 'Enquiry funnel': 'पूछताछ फ़नल', 'Last 6 months': 'पिछले 6 महीने', 'Six-month trend': 'छह महीने का रुझान',
  'Departures in 90 days': '90 दिन में रवानगी', 'Leaving soon': 'जल्द रवाना', 'Leaving in 14 days': '14 दिन में रवाना', 'Due in 7 days': '7 दिन में देय',
  'All bookings': 'सभी बुकिंग', 'All departures': 'सभी रवानगी', 'All enquiries': 'सभी पूछताछ', 'All tasks': 'सभी कार्य', 'All notifications': 'सभी सूचनाएं',
  'Team this month': 'इस महीने की टीम', 'Average booking': 'औसत बुकिंग', 'Billed (with GST)': 'बिल (GST सहित)', 'At risk': 'जोखिम में', 'New (30 days)': 'नए (30 दिन)',
  'Agents': 'एजेंट', 'Call': 'कॉल', 'WhatsApp': 'व्हाट्सऐप', 'Until': 'तक', 'From': 'से',
  // empty / status texts
  'No open tasks': 'कोई खुला कार्य नहीं', 'Nothing completed yet': 'अभी तक कुछ पूरा नहीं हुआ', 'No contacts yet': 'अभी कोई संपर्क नहीं', 'No one found': 'कोई नहीं मिला',
  'No bookings here': 'यहां कोई बुकिंग नहीं', 'No group departures': 'कोई ग्रुप टूर नहीं', 'No messages yet': 'अभी कोई संदेश नहीं', 'No approved templates': 'कोई स्वीकृत टेम्पलेट नहीं',
  'No target set yet': 'अभी लक्ष्य तय नहीं', 'Nothing found': 'कुछ नहीं मिला', 'Nothing yet': 'अभी कुछ नहीं', 'Try another word.': 'कोई और शब्द आज़माएं।',
  'Can reply now': 'अभी जवाब दे सकते हैं', 'Say hello once the customer writes in.': 'ग्राहक के संदेश करते ही नमस्ते कहें।',
  'Your session has ended.': 'आपका सत्र समाप्त हो गया।', 'Please sign in again': 'कृपया फिर साइन इन करें', 'Could not open this screen': 'यह स्क्रीन नहीं खुल सकी',
  'Check your internet connection and try again.': 'अपना इंटरनेट जांचें और फिर कोशिश करें।', 'Help and support': 'मदद और सहायता',
  'Hide details on the lock screen': 'लॉक स्क्रीन पर विवरण छिपाएं', 'Turn quiet hours off': 'शांत समय बंद करें',
  'Add a follow-up so no enquiry goes cold.': 'फॉलो-अप जोड़ें ताकि कोई पूछताछ ठंडी न पड़े।', 'No open tasks.': 'कोई खुला कार्य नहीं।',
  // Settings (language and theme)
  'Appearance': 'दिखावट', 'Language': 'भाषा', 'Automatic (follow the phone)': 'अपने आप (फोन के अनुसार)', 'Light': 'हल्का', 'Dark': 'गहरा',
  'English': 'English', 'Hindi': 'हिन्दी', 'Close and reopen the app to apply this.': 'इसे लागू करने के लिए ऐप बंद करके दोबारा खोलें।',
}

export const tr = (s) => (lang === 'hi' && typeof s === 'string' && HI[s]) || s
