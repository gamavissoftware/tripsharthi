// Point this at the TripSarthi API. A physical phone cannot reach "localhost":
// use your computer's LAN IP while developing (e.g. http://192.168.1.20:8731) or the production URL.
import { Platform } from 'react-native'

// Browser preview (developer tool only, not a shipped target) goes through scripts/dev-cors-proxy.py on :8085.
// A real build (release, or an over-the-air update) must NEVER fall back to localhost: when an update was once published without the EAS
// environment variable, every phone that installed it lost its connection. Only a development session may use the local server.
export const API_URL = process.env.EXPO_PUBLIC_API_URL || (Platform.OS === 'web' ? 'http://localhost:8085' : (__DEV__ ? 'http://localhost:8731' : 'https://app.tripsarthi.com'))

// The web app (shown inside the phone app for every screen that is not built natively). In production it lives on the same host as the API.
export const WEB_URL = process.env.EXPO_PUBLIC_WEB_URL || API_URL

// The TripSarthi team's admin site (shown inside the app for platform admins). In production: the same address with admin. in front of app.
export const ADMIN_URL = process.env.EXPO_PUBLIC_ADMIN_URL || API_URL.replace('//app.', '//admin.')
