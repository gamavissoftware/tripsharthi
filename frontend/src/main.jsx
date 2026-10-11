import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import '@fontsource-variable/inter'
import './index.css'
import './theme.css'
import App from './App.jsx'
import { captureReferral } from './lib/referral'
import { installClipboardFallback } from './lib/clipboardFallback'
import { setupImpersonation } from './lib/impersonation'

setupImpersonation()                               // a TripSarthi support session opened from the admin app (separate token, this tab only)
captureReferral()                                  // remember ?ref=PARTNERCODE from a partner's link before anything else runs

installClipboardFallback()

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <App />
  </StrictMode>,
)
