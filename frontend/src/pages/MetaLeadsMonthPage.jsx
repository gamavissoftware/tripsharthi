/**
 * Every lead Meta holds for one month, with everything the person typed,
 * cross-checked against the CRM — and a bulk "add to contacts" so the ones
 * not yet in TripSarthi (or not won) can be tagged and nurtured on WhatsApp.
 *
 * Reached from Analytics → Meta cost → the Leads count of a month.
 */
import { useEffect, useMemo, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { ArrowLeft, Download, UserPlus, ChevronDown, ChevronRight, Search } from 'lucide-react'
import { metaLeads } from '../api/metaLeads'
import { toast } from '../components/Toast'
import Loader from '../components/Loader'
import { MetricTile, Section, num } from '../components/analytics/reportUi'

const STATUS_TONE = { new: '#0a6cc4', contacted: '#0ea5e9', qualified: '#12a89e', won: '#16a34a', lost: '#dc2626' }
const monthLabel = (m) => {
  const [y, mo] = m.split('-').map(Number)
  return new Date(Date.UTC(y, mo - 1, 1)).toLocaleDateString('en-IN', { month: 'long', year: 'numeric', timeZone: 'UTC' })
}
const when = (s) => s ? new Date(s.replace(' ', 'T') + 'Z').toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : ''

export default function MetaLeadsMonthPage() {
  const { month } = useParams()
  const [data, setData]       = useState(null)
  const [loading, setLoading] = useState(true)
  const [filter, setFilter]   = useState('not_in_crm')
  const [q, setQ]             = useState('')
  const [open, setOpen]       = useState({})
  const [picked, setPicked]   = useState({})
  const [tag, setTag]         = useState('')
  const [startFlows, setStartFlows] = useState(false)
  const [importing, setImporting]   = useState(false)

  async function load() {
    setLoading(true)
    try {
      const r = await metaLeads.month(month)
      setData(r.data ?? null)
      // A month with nothing left to add should not open on an empty list.
      if ((r.data?.counts?.not_in_crm ?? 0) === 0 && filter === 'not_in_crm') setFilter('all')
      if (!tag) setTag(`Meta Ads · ${new Date(Date.UTC(...month.split('-').map((v, i) => Number(v) - (i === 1 ? 1 : 0)), 1)).toLocaleDateString('en-IN', { month: 'short', year: 'numeric', timeZone: 'UTC' })}`)
    } catch (e) {
      toast.error('Could not read leads from Meta', e.message)
      setData(null)
    } finally {
      setLoading(false)
    }
  }
  useEffect(() => { load() }, [month]) // eslint-disable-line react-hooks/exhaustive-deps

  const leads = data?.leads ?? []
  const shown = useMemo(() => {
    const s = q.trim().toLowerCase()
    return leads.filter(l => {
      if (filter === 'not_in_crm' && l.contact) return false
      if (filter === 'not_won' && (!l.contact || l.contact.status === 'won')) return false
      if (filter === 'in_crm' && !l.contact) return false
      if (!s) return true
      return [l.name, l.wa_number, l.email, l.company, l.form_name, l.campaign_name, ...(l.answers ?? []).map(a => a.answer)]
        .join(' ').toLowerCase().includes(s)
    })
  }, [leads, filter, q])

  const pickedIds = shown.filter(l => picked[l.leadgen_id]).map(l => l.leadgen_id)
  const allShownPicked = shown.length > 0 && pickedIds.length === shown.length

  async function doImport(ids) {
    if (ids.length === 0) return
    if (startFlows && !window.confirm(`This will start the welcome automation for ${ids.length} lead(s) — each gets the welcome WhatsApp now. Continue?`)) return
    setImporting(true)
    try {
      const r = await metaLeads.import({ month, leadgen_ids: ids, start_flows: startFlows, tag })
      const d = r.data ?? {}
      toast.success('Added to contacts', `${d.inserted ?? 0} new · ${d.updated ?? 0} updated · ${d.skipped ?? 0} skipped (no phone/email) · tagged "${d.tag}"`)
      setPicked({})
      await load()
    } catch (e) {
      toast.error('Import failed', e.message)
    } finally {
      setImporting(false)
    }
  }

  function exportCsv() {
    const cols = ['created_at', 'name', 'wa_number', 'email', 'company', 'job_title', 'form_name', 'campaign_name', 'ad_name', 'platform']
    const questions = [...new Set(leads.flatMap(l => (l.answers ?? []).map(a => a.question)))]
    const esc = (v) => `"${String(v ?? '').replace(/"/g, '""')}"`
    const rows = [[...cols, 'crm_status', ...questions].map(esc).join(',')]
    for (const l of shown) {
      const byQ = Object.fromEntries((l.answers ?? []).map(a => [a.question, a.answer]))
      rows.push([...cols.map(c => l[c]), l.contact?.status ?? 'not in CRM', ...questions.map(qn => byQ[qn] ?? '')].map(esc).join(','))
    }
    const blob = new Blob(['﻿' + rows.join('\n')], { type: 'text/csv;charset=utf-8' })
    const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = `meta-leads-${month}.csv`; a.click()
    URL.revokeObjectURL(a.href)
  }

  const c = data?.counts ?? {}

  return (
    <div className="page" style={{ maxWidth: 1280, margin: '0 auto' }}>
      <div className="page-header" style={{ alignItems: 'flex-start', flexWrap: 'wrap', gap: '.75rem' }}>
        <div>
          <Link to="/analytics" style={{ fontSize: '.8rem', color: 'var(--text-3)', display: 'inline-flex', alignItems: 'center', gap: 4, textDecoration: 'none' }}>
            <ArrowLeft size={13} /> Analytics · Meta cost
          </Link>
          <h1 className="page-title" style={{ marginTop: 4 }}>Meta leads · {monthLabel(month)}</h1>
          <div style={{ fontSize: '.8rem', color: 'var(--text-3)' }}>
            Read live from Meta{data ? ` · ${data.forms?.length ?? 0} lead form${(data.forms?.length ?? 0) === 1 ? '' : 's'} on the linked Page` : ''}. Meta keeps lead-ad submissions for 90 days.
          </div>
        </div>
        <button className="btn btn-ghost btn-sm" onClick={exportCsv} disabled={!shown.length}><Download size={14} /> Export shown as CSV</button>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))', gap: '.85rem', marginBottom: '1.25rem' }}>
        {loading ? Array.from({ length: 4 }).map((_, i) => <div key={i} className="lp-skeleton" style={{ height: 96 }} />) : <>
        <MetricTile label="Leads in month" value={num(c.total)} tone="brand" />
        <MetricTile label="Already in CRM" value={num(c.in_crm)} sub={`${num(c.won)} won`} />
        <MetricTile label="Not in CRM yet" value={num(c.not_in_crm)} tone={c.not_in_crm ? 'warn' : 'default'} sub="add them below" />
        <MetricTile label="Without a phone" value={num(c.no_phone)} sub="cannot be messaged" />
        </>}
      </div>

      <Section
        title="Leads"
        right={(
          <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
            <select className="form-input" value={filter} onChange={e => setFilter(e.target.value)} style={{ width: 'auto', padding: '.35rem .6rem' }}>
              <option value="not_in_crm">Not in CRM yet</option>
              <option value="not_won">In CRM, not won</option>
              <option value="in_crm">In CRM</option>
              <option value="all">All leads</option>
            </select>
            <div style={{ position: 'relative' }}>
              <Search size={13} style={{ position: 'absolute', left: 9, top: 10, color: 'var(--text-3)' }} />
              <input className="form-input" value={q} onChange={e => setQ(e.target.value)} placeholder="Search name, number, answer…" style={{ paddingLeft: 26, width: 240 }} />
            </div>
          </div>
        )}
      >
        {/* Bulk bar */}
        <div style={{ display: 'flex', gap: 10, alignItems: 'center', flexWrap: 'wrap', padding: '.75rem 1rem', background: 'var(--surface-2)', borderRadius: 'var(--r-md)', marginBottom: '1rem' }}>
          <label style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: '.8rem', fontWeight: 600 }}>
            <input type="checkbox" checked={allShownPicked} onChange={e => { const p = {}; if (e.target.checked) for (const l of shown) p[l.leadgen_id] = true; setPicked(p) }} />
            Select all shown ({shown.length})
          </label>
          <span style={{ fontSize: '.8rem', color: 'var(--text-3)' }}>Tag as</span>
          <input className="form-input" value={tag} onChange={e => setTag(e.target.value)} style={{ width: 200, padding: '.35rem .6rem' }} />
          <label style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: '.8rem' }} title="Off: contacts are created quietly for a campaign later. On: each gets the welcome WhatsApp now.">
            <input type="checkbox" checked={startFlows} onChange={e => setStartFlows(e.target.checked)} />
            Start welcome automation
          </label>
          <button className="btn btn-primary btn-sm" disabled={importing || pickedIds.length === 0} onClick={() => doImport(pickedIds)} style={{ marginLeft: 'auto' }}>
            {importing ? <Loader size="sm" inline message="Adding…" /> : <><UserPlus size={14} /> Add {pickedIds.length} to contacts</>}
          </button>
        </div>

        {loading ? (
          <Loader message={`Asking Meta for ${monthLabel(month)}'s leads…`} hint="Every form on your Page is read live, so this takes a few seconds." rows={6} />
        ) : shown.length === 0 ? (
          <div style={{ color: 'var(--text-3)', fontSize: '.85rem' }}>{leads.length ? 'Nothing matches this filter.' : 'Meta returned no leads for this month.'}</div>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: '.82rem' }}>
              <thead>
                <tr style={{ color: 'var(--text-3)', textAlign: 'left' }}>
                  <th style={{ padding: '.4rem .5rem', width: 28 }} />
                  <th style={{ padding: '.4rem .5rem', width: 20 }} />
                  <th style={{ padding: '.4rem .5rem' }}>When</th>
                  <th style={{ padding: '.4rem .5rem' }}>Lead</th>
                  <th style={{ padding: '.4rem .5rem' }}>WhatsApp</th>
                  <th style={{ padding: '.4rem .5rem' }}>Company</th>
                  <th style={{ padding: '.4rem .5rem' }}>Form / campaign</th>
                  <th style={{ padding: '.4rem .5rem' }}>In CRM</th>
                </tr>
              </thead>
              <tbody>
                {shown.map(l => {
                  const isOpen = !!open[l.leadgen_id]
                  return [
                    <tr key={l.leadgen_id} style={{ borderTop: '1px solid var(--border)' }}>
                      <td style={{ padding: '.5rem' }}><input type="checkbox" checked={!!picked[l.leadgen_id]} onChange={e => setPicked(p => ({ ...p, [l.leadgen_id]: e.target.checked }))} /></td>
                      <td style={{ padding: '.5rem', cursor: 'pointer' }} onClick={() => setOpen(o => ({ ...o, [l.leadgen_id]: !isOpen }))}>
                        {isOpen ? <ChevronDown size={14} /> : <ChevronRight size={14} />}
                      </td>
                      <td style={{ padding: '.5rem', whiteSpace: 'nowrap', color: 'var(--text-3)' }}>{when(l.created_at)}</td>
                      <td style={{ padding: '.5rem' }}>
                        <div style={{ fontWeight: 600 }}>{l.name || '—'}</div>
                        <div style={{ fontSize: '.72rem', color: 'var(--text-3)' }}>{l.email}{l.job_title ? ` · ${l.job_title}` : ''}</div>
                      </td>
                      <td style={{ padding: '.5rem', fontVariantNumeric: 'tabular-nums', whiteSpace: 'nowrap', color: l.wa_number ? 'var(--text)' : '#b45309' }}>{l.wa_number || 'no phone'}</td>
                      <td style={{ padding: '.5rem' }}>{l.company || '—'}</td>
                      <td style={{ padding: '.5rem', fontSize: '.75rem' }}>
                        <div>{l.form_name}</div>
                        <div style={{ color: 'var(--text-3)' }}>{[l.campaign_name, l.ad_name, l.platform].filter(Boolean).join(' · ')}{l.is_organic ? ' · organic' : ''}</div>
                      </td>
                      <td style={{ padding: '.5rem', whiteSpace: 'nowrap' }}>
                        {l.contact ? (
                          <Link to={`/contacts/${l.contact.id}`} style={{ textDecoration: 'none' }}>
                            <span style={{ background: (STATUS_TONE[l.contact.status] ?? '#6b7280') + '1a', color: STATUS_TONE[l.contact.status] ?? '#6b7280', borderRadius: 999, padding: '.15rem .6rem', fontSize: '.72rem', fontWeight: 700 }}>
                              {l.contact.status || 'in CRM'}
                            </span>
                          </Link>
                        ) : <span style={{ color: 'var(--text-3)', fontSize: '.75rem' }}>not yet</span>}
                      </td>
                    </tr>,
                    isOpen && (
                      <tr key={l.leadgen_id + '-answers'} style={{ background: 'var(--surface-2)' }}>
                        <td colSpan={8} style={{ padding: '.6rem 1rem .8rem 3rem' }}>
                          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(260px, 1fr))', gap: '.4rem 1.5rem', fontSize: '.78rem' }}>
                            {(l.answers ?? []).map((a, i) => (
                              <div key={i}><span style={{ color: 'var(--text-3)' }}>{a.question}</span><br /><span style={{ fontWeight: 600 }}>{a.answer || '—'}</span></div>
                            ))}
                          </div>
                          <div style={{ fontSize: '.7rem', color: 'var(--text-3)', marginTop: 6 }}>Meta lead id {l.leadgen_id}</div>
                        </td>
                      </tr>
                    ),
                  ]
                })}
              </tbody>
            </table>
          </div>
        )}
      </Section>

      <div style={{ fontSize: '.75rem', color: 'var(--text-3)', marginTop: '1rem' }}>
        Added contacts get the source “Meta Lead Ads”, every form answer as a custom field, and the tag above — filter Contacts by that tag to build a WhatsApp campaign segment.
        A lead already in the CRM is updated, never duplicated.
      </div>
    </div>
  )
}
