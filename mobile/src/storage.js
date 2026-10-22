import { Platform } from 'react-native'
import * as SecureStore from 'expo-secure-store'

// Phone: the encrypted keystore. Browser (developer preview only): localStorage, because SecureStore does not exist there.
const web = Platform.OS === 'web'
export const getItem = async (k) => (web ? (typeof localStorage !== 'undefined' ? localStorage.getItem(k) : null) : SecureStore.getItemAsync(k))
export const setItem = async (k, v) => { if (web) { localStorage.setItem(k, v) } else { await SecureStore.setItemAsync(k, v) } }
export const deleteItem = async (k) => { if (web) { localStorage.removeItem(k) } else { await SecureStore.deleteItemAsync(k) } }
