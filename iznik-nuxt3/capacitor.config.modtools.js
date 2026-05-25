// Capacitor config for the Freegle ModTools app.
// Used by build-android-modtools-debug and build-android-modtools CI jobs.
// The modtools Nuxt app is built separately (cd modtools && npm run generate)
// and synced into the shared Android/iOS native projects via cap sync.

/** @type {import('@capacitor/cli').CapacitorConfig} */
const config = {
  appId: 'org.ilovefreegle.modtools',
  appName: 'Freegle ModTools',
  webDir: 'modtools/.output/public',
  bundledWebRuntime: false,
  zoomEnabled: true,

  server: {
    hostname: 'ilovefreegle.org',
    androidScheme: 'https',
  },

  cordova: {
    preferences: {
      AndroidLaunchMode: 'singleTask',
    },
  },

  android: {
    includePlugins: [
      '@freegle/capacitor-push-notifications-cap7',
      '@capawesome/capacitor-badge',
      '@capgo/capacitor-social-login',
      '@capacitor/app',
      '@capacitor/status-bar',
      '@capacitor/network',
      '@capacitor/device',
      '@capacitor/browser',
      '@capacitor/app-launcher',
    ],
    buildOptions: {
      releaseType: 'APK',
    },
  },

  ios: {
    scheme: 'App',
    contentInset: 'automatic',
    includePlugins: [
      '@freegle/capacitor-push-notifications-cap7',
      '@capawesome/capacitor-badge',
      '@capgo/capacitor-social-login',
      '@capacitor-community/apple-sign-in',
      '@capacitor/app',
      '@capacitor/status-bar',
      '@capacitor/network',
      '@capacitor/device',
      '@capacitor/browser',
      '@capacitor/app-launcher',
    ],
  },

  plugins: {
    StatusBar: {
      overlaysWebView: false,
      backgroundColor: '#00000000',
    },
    PushNotifications: {
      presentationOptions: ['badge'],
    },
    Badge: {
      persist: true,
      autoClear: false,
    },
  },
}

module.exports = config
