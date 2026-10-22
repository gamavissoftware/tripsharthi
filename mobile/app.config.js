// Everything else lives in app.json. This file only adds the Firebase file for Android push notifications, which must NOT be committed to the
// (public) repository: it comes from the EAS file secret GOOGLE_SERVICES_JSON when building in the cloud, or from a local, git-ignored
// google-services.json when building on this computer. Without either, the app still builds; it just cannot receive pushes.
const fs = require('fs')

module.exports = ({ config }) => {
  const fromEnv = process.env.GOOGLE_SERVICES_JSON
  const local = './google-services.json'
  const file = fromEnv || (fs.existsSync(local) ? local : undefined)
  return file ? { ...config, android: { ...config.android, googleServicesFile: file } } : config
}
