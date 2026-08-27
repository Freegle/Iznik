import { Capacitor, registerPlugin } from '@capacitor/core'

// Google Block Store, wrapped. Block Store is a small key/value store that Android carries to
// a new device during setup, either by direct transfer or from cloud backup. We put the
// long-lived `persistent` session token in it so someone who replaces their phone is still
// logged in, which is what Play calls Zero-Tap Sign-In restoration (required from April 2027;
// a Block Store integration shipped by 30 September 2026 counts as meeting it).
//
// The native side is android/app/src/main/java/org/freegle/blockstore/BlockStorePlugin.java,
// registered in MainActivity rather than coming from node_modules. registerPlugin only builds
// a proxy, so importing this file on web or iOS is harmless - every call is behind the
// platform check below.
const BlockStore = registerPlugin('BlockStore')

// Bump when the stored shape changes, so an older blob is ignored rather than misread.
const SCHEMA = 1

// Android only. Block Store is an Android API, and iOS already carries a session across a
// device restore in the encrypted keychain backup.
export function sessionRestoreSupported() {
  return import.meta.client && Capacitor.getPlatform() === 'android'
}

// What Block Store currently holds, so we can skip pointless rewrites: setAuth fires on every
// session refresh, not just at login.
let stored = null

export async function saveSessionForRestore(persistent) {
  if (!sessionRestoreSupported() || !persistent) {
    return false
  }

  const value = JSON.stringify({ v: SCHEMA, persistent })

  if (value === stored) {
    return false
  }

  try {
    await BlockStore.setSession({ value })
    stored = value
    return true
  } catch (e) {
    // Nothing the user can do, and nothing breaks now - they just get a login prompt on
    // their next device.
    console.log('Could not save session for device restore', e?.message)
    return false
  }
}

export async function restoreSessionFromDevice() {
  if (!sessionRestoreSupported()) {
    return null
  }

  try {
    const ret = await BlockStore.getSession()

    if (ret?.error) {
      // getSession resolves rather than rejects when Block Store is unavailable, so this is
      // the only place the reason surfaces.
      console.log('Block Store unavailable', ret.error)
    }

    if (!ret?.value) {
      return null
    }

    const parsed = JSON.parse(ret.value)

    if (parsed?.v !== SCHEMA || !parsed.persistent) {
      return null
    }

    // We now hold exactly what the store holds; don't write it straight back.
    stored = ret.value

    return parsed.persistent
  } catch (e) {
    console.log('Could not restore session from device', e?.message)
    return null
  }
}

export async function clearRestoredSession() {
  stored = null

  if (!sessionRestoreSupported()) {
    return
  }

  try {
    await BlockStore.clearSession()
  } catch (e) {
    console.log('Could not clear the restorable session', e?.message)
  }
}
