import * as SecureStore from 'expo-secure-store'
import * as LocalAuthentication from 'expo-local-authentication'

const KEY = 'tp_lock'

export async function lockEnabled() { try { return (await SecureStore.getItemAsync(KEY)) === '1' } catch { return false } }

/** Hardware present AND a face/fingerprint/passcode set up on this phone. */
export async function lockAvailable() {
  try { return (await LocalAuthentication.hasHardwareAsync()) && (await LocalAuthentication.isEnrolledAsync()) } catch { return false }
}

export async function authenticate(message = 'Unlock TripSarthi') {
  try {
    const r = await LocalAuthentication.authenticateAsync({ promptMessage: message, cancelLabel: 'Cancel', fallbackLabel: 'Use passcode' })
    return r.success === true
  } catch { return false }
}

/** Turning the lock ON requires proving it works first, so a user can never lock themselves out of an unusable setup. */
export async function setLockEnabled(on) {
  if (on) {
    if (!(await lockAvailable())) throw new Error('Set up Face ID, a fingerprint or a screen lock on this phone first.')
    if (!(await authenticate('Confirm to turn on app lock'))) throw new Error('Could not verify it is you.')
    await SecureStore.setItemAsync(KEY, '1')
  } else {
    await SecureStore.deleteItemAsync(KEY)
  }
}
