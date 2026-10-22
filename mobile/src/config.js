// Point this at the TripSarthi API. A physical phone cannot reach "localhost":
// use your computer's LAN IP while developing (e.g. http://192.168.1.20:8731) or the production URL.
import { Platform } from 'react-native'

// Browser preview (developer tool only, not a shipped target) goes through scripts/dev-cors-proxy.py on :8085.
export const API_URL = process.env.EXPO_PUBLIC_API_URL || (Platform.OS === 'web' ? 'http://localhost:8085' : 'http://localhost:8731')

// The web app (shown inside the phone app for every screen that is not built natively). In production it lives on the same host as the API.
export const WEB_URL = process.env.EXPO_PUBLIC_WEB_URL || API_URL

// The TripSarthi team's admin site (shown inside the app for platform admins). In production: the same address with admin. in front of app.
export const ADMIN_URL = process.env.EXPO_PUBLIC_ADMIN_URL || API_URL.replace('//app.', '//admin.')
