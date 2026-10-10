import { useState, useEffect } from 'react'
import { X, Check } from 'lucide-react'
import { api } from '../api/client'
import { Link } from 'react-router-dom'

const DISMISSED_KEY = 'tp_onboarding_dismissed'

export default function OnboardingBanner({ user }) {
  const [visible, setVisible] = useState(false)
  const [loading, setLoading] = useState(true)
  const [wabaConnected, setWabaConnected] = useState(false)
  const [templateCreated, setTemplateCreated] = useState(false)
  const [contactImported, setContactImported] = useState(false)
  const [allDone, setAllDone] = useState(false)

  useEffect(() => {
    if (!user || user.role !== 'owner') return
    if (localStorage.getItem(DISMISSED_KEY) === '1') return
    setVisible(true)

    Promise.all([
      api.get('/waba').catch(() => null),
      api.get('/templates').catch(() => null),
      api.get('/contacts').catch(() => null),
    ]).then(([wabaRes, templatesRes, contactsRes]) => {
      const waba = !!(wabaRes && (Array.isArray(wabaRes) ? wabaRes.length > 0 : wabaRes.id || wabaRes.waba_id))
      const tmpl = !!(templatesRes && (Array.isArray(templatesRes) ? templatesRes.length > 0 : templatesRes.data && templatesRes.data.length > 0))
      const ctct = !!(contactsRes && (contactsRes.contacts_total > 0 || contactsRes.total > 0 || (Array.isArray(contactsRes) && contactsRes.length > 0) || (contactsRes.data && contactsRes.data.length > 0)))
      setWabaConnected(waba)
      setTemplateCreated(tmpl)
      setContactImported(ctct)
      setAllDone(waba && tmpl && ctct)
      setLoading(false)
    })
  }, [user])

  useEffect(() => {
    if (!allDone) return
    const timer = setTimeout(() => dismiss(), 5000)
    return () => clearTimeout(timer)
  }, [allDone])

  function dismiss() {
    localStorage.setItem(DISMISSED_KEY, '1')
    setVisible(false)
  }

  if (!visible) return null

  const steps = [
    { label: 'Connect WhatsApp', done: wabaConnected, to: '/settings/waba' },
    { label: 'Create a template', done: templateCreated, to: '/templates' },
    { label: 'Import contacts', done: contactImported, to: '/imports' },
  ]
  const completeCount = steps.filter(s => s.done).length

  return (
    <div
      className="card"
      style={{
        background: '#fffbeb',
        border: '1px solid #f59e0b',
        borderRadius: 10,
        margin: '.75rem 1.5rem 0',
        position: 'relative',
        boxShadow: '0 1px 4px rgba(245,158,11,0.10)',
      }}
    >
      <button
        onClick={dismiss}
        aria-label="Dismiss"
        style={{
          position: 'absolute',
          top: 10,
          right: 12,
          background: 'none',
          border: 'none',
          cursor: 'pointer',
          color: '#92400e',
          lineHeight: 1,
          padding: '2px 4px',
          display: 'inline-flex',
          alignItems: 'center',
        }}
      >
        <X size={16} />
      </button>

      <div className="card-body" style={{ padding: '10px 40px 10px 16px', display: 'flex', alignItems: 'center', gap: '.5rem 1.5rem', flexWrap: 'wrap' }}>
        <div>
          <span style={{ fontWeight: 600, fontSize: 14, color: '#78350f' }}>
            Get started with TripSarthi
          </span>
          <span style={{ marginLeft: 10, fontSize: 12, color: '#92400e' }}>3 steps to send your first message</span>
        </div>

        {loading ? (
          <p style={{ fontSize: 12, color: '#b45309' }}>Loading...</p>
        ) : allDone ? (
          <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
            <span style={{ fontSize: 13, color: '#065f46', fontWeight: 600 }}>
              You're all set! Ready to send your first campaign.
            </span>
            <Link
              to="/campaigns"
              className="btn btn-primary btn-sm"
              style={{ fontSize: 12, padding: '4px 12px' }}
            >
              Go to Campaigns →
            </Link>
          </div>
        ) : (
          <>
            <div style={{ display: 'flex', flexDirection: 'row', flexWrap: 'wrap', gap: '6px 18px' }}>
              {steps.map((step, i) => (
                <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                  <span
                    style={{
                      width: 20,
                      height: 20,
                      borderRadius: '50%',
                      border: step.done ? '2px solid #16a34a' : '2px solid #d1d5db',
                      background: step.done ? '#16a34a' : '#fff',
                      display: 'flex',
                      alignItems: 'center',
                      justifyContent: 'center',
                      flexShrink: 0,
                      fontSize: 11,
                      color: '#fff',
                      fontWeight: 700,
                    }}
                  >
                    {step.done && <Check size={12} strokeWidth={3} />}
                  </span>
                  {step.done ? (
                    <span style={{ fontSize: 13, color: '#6b7280', textDecoration: 'line-through' }}>
                      {step.label}
                    </span>
                  ) : (
                    <Link
                      to={step.to}
                      style={{ fontSize: 13, color: '#b45309', textDecoration: 'underline', fontWeight: 500 }}
                    >
                      {step.label}
                    </Link>
                  )}
                </div>
              ))}
            </div>
            <span style={{ fontSize: 11, color: '#92400e' }}>
              {completeCount} of 3 complete
            </span>
          </>
        )}
      </div>
    </div>
  )
}
