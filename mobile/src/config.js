// Point this at the TripSarthi API. A physical phone cannot reach "localhost":
// use your computer's LAN IP while developing (e.g. http://192.168.1.20:8731) or the production URL.
import { Platform } from 'react-native'

// Browser preview (developer tool only, not a shipped target) goes through scripts/dev-cors-proxy.py on :8085.
export const API_URL = process.env.EXPO_PUBLIC_API_URL || (Platform.OS === 'web' ? 'http://localhost:8085' : 'http://localhost:8731')
