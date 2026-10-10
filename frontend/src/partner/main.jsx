import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import '@fontsource-variable/inter'
import '../index.css'
import PartnerApp from './PartnerApp.jsx'

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <PartnerApp />
  </StrictMode>,
)
