// Point this at the TripSarthi API. A physical phone cannot reach "localhost":
// use your computer's LAN IP while developing (e.g. http://192.168.1.20:8731) or the production URL.
export const API_URL = process.env.EXPO_PUBLIC_API_URL || 'http://localhost:8731'
