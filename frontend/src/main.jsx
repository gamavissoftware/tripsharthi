import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import '@fontsource-variable/inter'
import './index.css'
import App from './App.jsx'
import { captureReferral } from './lib/referral'

captureReferral()                                  // remember ?ref=PARTNERCODE from a partner's link before anything else runs

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <App />
  </StrictMode>,
)
