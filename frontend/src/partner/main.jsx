import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import '@fontsource-variable/inter'
import '../index.css'
import PartnerApp from './PartnerApp.jsx'
import { installClipboardFallback } from '../lib/clipboardFallback'
import { installFormValidation } from '../lib/formValidation'

installClipboardFallback()
installFormValidation()                          // red highlight + message + toast for every form's required / format checks

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <PartnerApp />
  </StrictMode>,
)
