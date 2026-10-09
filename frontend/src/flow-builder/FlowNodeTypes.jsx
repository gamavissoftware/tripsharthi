/**
 * Custom @xyflow/react node components.
 *
 * Each node's Handle `id` values are the EXACT sourceHandle strings
 * the engine reads in FlowEngine::findEdge(). No translation.
 *
 * Handle layout per node category:
 *   Trigger:       no input target | one source id="next"
 *   Action:        one input       | one source id="next"
 *   send_freeform  one input       | source id="next" (right) + id="window_closed" (bottom-right, orange)
 *   send_media:    same as send_freeform
 *   condition:     one input       | source id="true" (left) + id="false" (right)
 *   window_check:  one input       | source id="open" (left)  + id="closed" (right)
 *   delay:         one input       | one source id="next"
 *
 * The "window_closed" edge is saved IF AND ONLY IF the user drew a connection
 * from that handle. The graph's edges[] array is the source of truth — no
 * checkbox or flag drives what gets saved.
 */
import { Handle, Position } from '@xyflow/react'
import {
  UserPlus, Tag, ClipboardList, Megaphone, Globe, MessageCircle, Inbox,
  ShoppingCart, Package, ShoppingBag, FileText, Cake,
  Send, MessageSquare, Image, Plus, Minus, RefreshCw, User, Hand,
  CreditCard, Bot, NotebookPen, Webhook, MousePointerClick,
  Timer, GitBranch, Hourglass, AlertTriangle,
  Briefcase, Shuffle, Trophy, HeartCrack, Ticket, CircleCheckBig, Calendar,
  AlarmClock, SquareCheck, Pencil, BellRing,
  CalendarClock, CalendarCheck, Mail,
} from 'lucide-react'
import { TRAVEL_TRIGGERS } from './travelTriggers'

// ── Visual metadata ────────────────────────────────────────────────────
// `icon` is a lucide-react component, rendered only in NodeShell, the
// send_interactive header, and the FlowCanvas palette — never persisted.
export const NODE_META = {
  // Triggers (green)
  lead_created:       { label:'Lead Created',     icon:UserPlus, group:'trigger', desc:'Any new contact (all sources)' },
  tag_added:          { label:'Tag Added',         icon:Tag, group:'trigger', desc:'Contact receives a tag' },
  form_submitted:     { label:'Form Submitted',    icon:ClipboardList, group:'trigger', desc:'Web form submission' },
  meta_lead_received: { label:'Meta Lead',         icon:Megaphone, group:'trigger', desc:'Facebook/Instagram Lead Ad' },
  google_lead_received:{ label:'Google Lead',      icon:Globe, group:'trigger', desc:'Google Ads Lead Form' },
  keyword_reply:      { label:'Keyword Reply',     icon:MessageCircle, group:'trigger', desc:'Inbound text matches keyword' },
  inbound_message:    { label:'Any Inbound',       icon:Inbox, group:'trigger', desc:'Any inbound message' },
  order_placed:       { label:'Order Placed',      icon:ShoppingCart, group:'trigger', desc:'Shopify/Woo new order' },
  order_fulfilled:    { label:'Order Fulfilled',   icon:Package, group:'trigger', desc:'Order shipped/completed' },
  abandoned_cart:     { label:'Abandoned Cart',    icon:ShoppingBag, group:'trigger', desc:'Checkout not completed' },
  flow_response:      { label:'Form Response',     icon:FileText, group:'trigger', desc:'WhatsApp Flow submitted' },
  date_reached:       { label:'Date Reached',      icon:Cake, group:'trigger', desc:'Birthday / date-based' },
  deal_created:       { label:'Deal Created',      icon:Briefcase, group:'trigger', desc:'New deal opened' },
  deal_stage_changed: { label:'Deal Stage Moved',  icon:Shuffle, group:'trigger', desc:'Deal moved to a stage' },
  deal_won:           { label:'Deal Won',          icon:Trophy, group:'trigger', desc:'Deal marked won' },
  deal_lost:          { label:'Deal Lost',         icon:HeartCrack, group:'trigger', desc:'Deal marked lost' },
  ticket_created:     { label:'Ticket Created',    icon:Ticket, group:'trigger', desc:'New support ticket' },
  ticket_resolved:    { label:'Ticket Resolved',   icon:CircleCheckBig, group:'trigger', desc:'Ticket marked resolved' },
  meeting_scheduled:  { label:'Meeting Scheduled', icon:Calendar, group:'trigger', desc:'A meeting was booked' },
  record_created:     { label:'Record Created',    icon:Package, group:'trigger', desc:'A custom-object record was created (linked to a contact)' },
  task_due:           { label:'Task Due',          icon:AlarmClock, group:'trigger', desc:'Task reminder matured' },
  meeting_reminder:   { label:'Meeting Reminder',  icon:BellRing, group:'trigger', desc:'30 min before a booked meeting starts' },
  no_reply_followup:  { label:'No Reply Follow-up', icon:MessageCircle, group:'trigger', desc:'Sent a template, never got an answer' },
  // Travel triggers (derived from travelTriggers.js — single source)
  ...Object.fromEntries(TRAVEL_TRIGGERS.map(t => [t.key, { label: t.label, icon: t.icon, group: 'trigger', desc: t.desc }])),
  // Actions (indigo)
  send_template:      { label:'Send Template',     icon:Send, group:'action',  desc:'Approved template message' },
  send_freeform:      { label:'Send Text',         icon:MessageSquare, group:'action',  desc:'Free text (window open only)' },
  send_media:         { label:'Send Media',        icon:Image, group:'action',  desc:'Image/doc/video (window open only)' },
  add_tag:            { label:'Add Tag',           icon:Plus, group:'action',  desc:'' },
  remove_tag:         { label:'Remove Tag',        icon:Minus, group:'action',  desc:'' },
  update_status:      { label:'Update Status',     icon:RefreshCw, group:'action',  desc:'' },
  assign_agent:       { label:'Assign Agent',      icon:User, group:'action',  desc:'' },
  handoff:            { label:'Handoff to Human',   icon:Hand, group:'action',  desc:'Assign to an agent & notify' },
  send_payment:       { label:'Request Payment',    icon:CreditCard, group:'action',  desc:'Send a Razorpay payment link' },
  send_product:       { label:'Send Product',       icon:ShoppingBag, group:'action',  desc:'Send a catalog product' },
  ai_reply:           { label:'AI Reply',           icon:Bot, group:'action',  desc:'Auto-reply with AI' },
  send_flow:          { label:'Send Form (Flow)',   icon:NotebookPen, group:'action',  desc:'Send a WhatsApp Flow form' },
  webhook_call:       { label:'Webhook',           icon:Webhook, group:'action',  desc:'' },
  send_interactive:   { label:'Interactive',       icon:MousePointerClick, group:'action',  desc:'Buttons, links, or list menus' },
  send_email:         { label:'Send Email',        icon:Mail, group:'action',  desc:'Email the contact (skips unsubscribed)' },
  create_task:        { label:'Create Task',       icon:SquareCheck, group:'action',  desc:'Open a CRM task on the contact' },
  update_field:       { label:'Update Field',      icon:Pencil, group:'action',  desc:'Set a contact field' },
  create_deal:        { label:'Create Deal',       icon:Briefcase, group:'action',  desc:'Open a deal in the pipeline' },
  create_ticket:      { label:'Create Ticket',     icon:Ticket, group:'action',  desc:'Open a support ticket' },
  notify_number:      { label:'Alert My Number',   icon:BellRing, group:'action',  desc:'WhatsApp the team when a lead acts' },
  send_slots:         { label:'Offer Call Slots',  icon:CalendarClock, group:'action',  desc:'WhatsApp list of free times (window open only)' },
  book_slot:          { label:'Book Tapped Slot',  icon:CalendarCheck, group:'control', desc:'Book the time the contact chose' },
  // Control (amber / purple)
  delay:              { label:'Delay',             icon:Timer, group:'control', desc:'Wait before continuing' },
  condition:          { label:'Condition',         icon:GitBranch, group:'control', desc:'Branch on field / tag' },
  window_check:       { label:'Window Check',      icon:Hourglass, group:'control', desc:'Branch on 24h window state' },
}

const COLORS = {
  trigger: { border:'#16a34a', bg:'#f0fdf4' },
  action:  { border:'#08569f', bg:'#e8f3fc' },
  control: { border:'#d97706', bg:'#fffbeb' },
  window:  { border:'#0e8f8c', bg:'#f5f3ff' },
}

function nodeColor(type) {
  if (type === 'window_check') return COLORS.window
  return COLORS[NODE_META[type]?.group ?? 'action']
}

// ── Handle styles ──────────────────────────────────────────────────────
const H = { width: 10, height: 10 }

// ── Shared node shell ──────────────────────────────────────────────────
function NodeShell({ type, children, selected }) {
  const { border, bg } = nodeColor(type)
  const meta = NODE_META[type] ?? { label: type, icon: null, desc: '' }
  const Icon = meta.icon
  return (
    <div style={{
      border: `2px solid ${selected ? '#1a1a2e' : border}`,
      borderLeft: `4px solid ${border}`,
      borderRadius: 8,
      background: bg,
      padding: '8px 12px',
      minWidth: 160,
      maxWidth: 220,
      boxShadow: selected ? `0 0 0 2px ${border}` : 'none',
      fontSize: 13,
    }}>
      <div style={{ fontWeight: 600, display: 'flex', alignItems: 'center', gap: 6, marginBottom: 4 }}>
        {Icon && <Icon size={14} strokeWidth={2} style={{ flexShrink: 0 }} />}
        <span>{meta.label}</span>
      </div>
      {children && <div style={{ color: '#64748b', fontSize: 11 }}>{children}</div>}
    </div>
  )
}

// ── TriggerNode — no target, one "next" source ─────────────────────────
export function TriggerNode({ type, data, selected }) {
  return (
    <NodeShell type={type} selected={selected}>
      {data.desc || NODE_META[type]?.desc}
      <Handle type="source" position={Position.Bottom} id="next" style={H} />
    </NodeShell>
  )
}

// ── ActionNode — one target, one "next" source ─────────────────────────
export function ActionNode({ type, data, selected }) {
  const summary = nodeDataSummary(type, data)
  return (
    <NodeShell type={type} selected={selected}>
      <Handle type="target" position={Position.Top} id="target" style={H} />
      {summary && <span style={{ fontStyle: 'italic' }}>{summary}</span>}
      <Handle type="source" position={Position.Bottom} id="next" style={H} />
    </NodeShell>
  )
}

// ── FreeformNode — target + "next" right + "window_closed" bottom-right ─
// window_closed handle is ALWAYS rendered.
// It appears in the saved graph's edges[] IF AND ONLY IF the user drew a connection.
export function FreeformNode({ type, data, selected }) {
  return (
    <NodeShell type={type} selected={selected}>
      <Handle type="target" position={Position.Top}   id="target" style={H} />
      {data.content && <span style={{ fontStyle:'italic', wordBreak:'break-word' }}>{String(data.content).slice(0,40)}{data.content.length > 40 ? '…' : ''}</span>}
      <Handle type="source" position={Position.Bottom} id="next" style={H} />
      {/* window_closed fallback — connects to a send_template node for compliant design */}
      <Handle
        type="source"
        position={Position.Right}
        id="window_closed"
        style={{ ...H, background: '#f97316' }}
        title="Window closed fallback (connect to a template send)"
      />
      <div style={{ position:'absolute', right:16, bottom:-14, fontSize:9, color:'#f97316' }}>
        window↓
      </div>
    </NodeShell>
  )
}

// ── ConditionNode — target + "true" left + "false" right ──────────────
export function ConditionNode({ type, data, selected }) {
  const op = data.operator ?? '?'
  const field = data.field ?? '?'
  const val = data.value ?? ''
  return (
    <NodeShell type={type} selected={selected}>
      <Handle type="target" position={Position.Top} id="target" style={H} />
      <span style={{ fontStyle:'italic' }}>
        {data.type === 'tag' ? `tag ${op}` : `${field} ${op}`}
        {val ? ` "${val}"` : ''}
      </span>
      <div style={{ display:'flex', justifyContent:'space-between', marginTop:6, fontSize:11 }}>
        <span style={{ color:'#16a34a', fontWeight:600 }}>✓ true</span>
        <span style={{ color:'#dc2626', fontWeight:600 }}>✗ false</span>
      </div>
      <Handle type="source" position={Position.Bottom} id="true"  style={{ ...H, left:'30%', background:'#16a34a' }} />
      <Handle type="source" position={Position.Bottom} id="false" style={{ ...H, left:'70%', background:'#dc2626' }} />
    </NodeShell>
  )
}

// ── WindowCheckNode — target + "open" left + "closed" right ───────────
export function WindowCheckNode({ type, data, selected }) {
  return (
    <NodeShell type={type} selected={selected}>
      <Handle type="target" position={Position.Top} id="target" style={H} />
      <span style={{ fontStyle:'italic', fontSize:11 }}>Is 24h service window open?</span>
      <div style={{ display:'flex', justifyContent:'space-between', marginTop:6, fontSize:11 }}>
        <span style={{ color:'#3b82f6', fontWeight:600 }}>↙ open</span>
        <span style={{ color:'#9ca3af', fontWeight:600 }}>closed ↘</span>
      </div>
      <Handle type="source" position={Position.Bottom} id="open"   style={{ ...H, left:'30%', background:'#3b82f6' }} />
      <Handle type="source" position={Position.Bottom} id="closed" style={{ ...H, left:'70%', background:'#9ca3af' }} />
    </NodeShell>
  )
}

// ── SlotsNode — target + "next" (no slots / no reply) + "fallback" (tap) ─
// The engine parks the run on this node and resumes it down `fallback` when
// the contact taps a time; `next` is taken when nothing could be offered.
export function SlotsNode({ type, data, selected }) {
  return (
    <NodeShell type={type} selected={selected}>
      <Handle type="target" position={Position.Top} id="target" style={H} />
      <span style={{ fontStyle:'italic' }}>{nodeDataSummary(type, data)}</span>
      <div style={{ display:'flex', justifyContent:'space-between', marginTop:6, fontSize:11 }}>
        <span style={{ color:'#9ca3af', fontWeight:600 }}>no slots ↙</span>
        <span style={{ color:'#08569f', fontWeight:600 }}>tapped ↘</span>
      </div>
      <Handle type="source" position={Position.Bottom} id="next"     style={{ ...H, left:'30%', background:'#9ca3af' }} />
      <Handle type="source" position={Position.Bottom} id="fallback" style={{ ...H, left:'70%', background:'#08569f' }} />
    </NodeShell>
  )
}

// ── BookSlotNode — target + "booked" / "failed" ────────────────────────
export function BookSlotNode({ type, data, selected }) {
  return (
    <NodeShell type={type} selected={selected}>
      <Handle type="target" position={Position.Top} id="target" style={H} />
      <span style={{ fontStyle:'italic' }}>{nodeDataSummary(type, data)}</span>
      <div style={{ display:'flex', justifyContent:'space-between', marginTop:6, fontSize:11 }}>
        <span style={{ color:'#16a34a', fontWeight:600 }}>✓ booked</span>
        <span style={{ color:'#dc2626', fontWeight:600 }}>✗ failed</span>
      </div>
      <Handle type="source" position={Position.Bottom} id="booked" style={{ ...H, left:'30%', background:'#16a34a' }} />
      <Handle type="source" position={Position.Bottom} id="failed" style={{ ...H, left:'70%', background:'#dc2626' }} />
    </NodeShell>
  )
}

// ── UnknownNode — fallback for node types this build doesn't know ──────
// Legacy graphs (and graphs written by a newer build) can carry types that
// aren't in the map. React Flow would otherwise draw an unlabelled empty box,
// leaving the user with no idea what the node is or why the flow won't validate.
function UnknownNode({ data, type, selected }) {
  return (
    <div style={{
      border: `2px dashed ${selected ? '#1a1a2e' : '#f59e0b'}`,
      borderRadius: 8,
      background: '#fffbeb',
      padding: '8px 12px',
      minWidth: 160,
      maxWidth: 220,
      fontSize: 13,
    }}>
      <Handle type="target" position={Position.Top} style={H} />
      <div style={{ fontWeight: 600, display: 'flex', alignItems: 'center', gap: 6, marginBottom: 4, color: '#b45309' }}>
        <AlertTriangle size={14} strokeWidth={2} style={{ flexShrink: 0 }} />
        <span>Unsupported node</span>
      </div>
      <div style={{ color: '#92400e', fontSize: 11 }}>
        type: {data?.__rawType ?? type ?? 'unknown'}
      </div>
      <Handle type="source" position={Position.Bottom} id="next" style={H} />
    </div>
  )
}

// ── nodeTypes map (must be stable — defined outside component) ─────────
export const nodeTypes = {
  // Triggers
  lead_created:       TriggerNode,
  tag_added:          TriggerNode,
  form_submitted:     TriggerNode,
  meta_lead_received: TriggerNode,
  google_lead_received: TriggerNode,
  keyword_reply:      TriggerNode,
  inbound_message:    TriggerNode,
  order_placed:       TriggerNode,
  order_fulfilled:    TriggerNode,
  abandoned_cart:     TriggerNode,
  flow_response:      TriggerNode,
  date_reached:       TriggerNode,
  deal_created:       TriggerNode,
  deal_stage_changed: TriggerNode,
  deal_won:           TriggerNode,
  deal_lost:          TriggerNode,
  ticket_created:     TriggerNode,
  ticket_resolved:    TriggerNode,
  meeting_scheduled:  TriggerNode,
  record_created:     TriggerNode,
  task_due:           TriggerNode,
  meeting_reminder:   TriggerNode,
  no_reply_followup:  TriggerNode,
  ...Object.fromEntries(TRAVEL_TRIGGERS.map(t => [t.key, TriggerNode])),
  // Standard actions
  send_template:  ActionNode,
  add_tag:        ActionNode,
  remove_tag:     ActionNode,
  update_status:  ActionNode,
  assign_agent:   ActionNode,
  handoff:        ActionNode,
  send_payment:   ActionNode,
  send_product:   ActionNode,
  ai_reply:       ActionNode,
  send_flow:      ActionNode,
  webhook_call:   ActionNode,
  send_email:     ActionNode,
  create_task:    ActionNode,
  update_field:   ActionNode,
  create_deal:    ActionNode,
  create_ticket:  ActionNode,
  delay:          ActionNode,
  // Freeform (window_closed handle)
  send_freeform:  FreeformNode,
  send_media:     FreeformNode,
  notify_number:  ActionNode,
  // Branch nodes
  condition:    ConditionNode,
  window_check: WindowCheckNode,
  send_slots:   SlotsNode,
  book_slot:    BookSlotNode,
  // Fallback for unrecognised types (legacy graphs, newer builds)
  default: UnknownNode,
  // Interactive message node
  send_interactive: ({ data, selected }) => {
    const { border, bg } = nodeColor('send_interactive')
    const meta = NODE_META['send_interactive']
    const MetaIcon = meta.icon
    const itype = data.interactive_type ?? 'button'
    const buttons = data.buttons ?? []
    const sections = data.sections ?? []
    const allRows = sections.flatMap(s => s.rows ?? [])
    const handles = itype === 'button' ? buttons
                  : itype === 'cta_url' ? [{id: 'cta', title: data.cta_text ?? 'CTA'}]
                  : allRows

    return (
      <div style={{
        border: `2px solid ${selected ? '#1a1a2e' : border}`,
        borderLeft: `4px solid ${border}`,
        borderRadius: 8,
        background: bg,
        padding: '8px 12px',
        minWidth: 180,
        maxWidth: 240,
        boxShadow: selected ? `0 0 0 2px ${border}` : 'none',
        fontSize: 13,
      }}>
        <Handle type="target" position={Position.Left} style={H} />
        <div style={{ fontWeight: 600, display: 'flex', alignItems: 'center', gap: 6, marginBottom: 4 }}>
          <MetaIcon size={14} strokeWidth={2} style={{ flexShrink: 0 }} />
          <span>{itype === 'button' ? 'Buttons' : itype === 'cta_url' ? 'CTA Link' : 'List Menu'}</span>
        </div>
        {data.body && (
          <div style={{ fontSize: 11, color: '#6b7280', marginBottom: 6, lineHeight: 1.3 }}>
            {data.body.slice(0, 50)}{data.body.length > 50 ? '…' : ''}
          </div>
        )}
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 4, marginBottom: 4 }}>
          {handles.slice(0, 3).map((h, i) => (
            <span key={h.id ?? i} style={{
              fontSize: 10, background: '#e0e7ff', color: '#074a8c',
              borderRadius: 4, padding: '1px 6px', border: '1px solid #bcdcf6',
            }}>
              {(h.title ?? h.id ?? '').slice(0, 18)}
            </span>
          ))}
        </div>
        {/* Output handles — one per button */}
        {handles.map((h, i) => (
          <Handle
            key={h.id ?? i}
            type="source"
            position={Position.Right}
            id={h.id ?? `btn_${i}`}
            style={{ ...H, top: `${25 + i * 22}%`, background: '#08569f' }}
          />
        ))}
        {/* Fallback handle */}
        <Handle
          type="source"
          position={Position.Bottom}
          id="fallback"
          style={{ ...H, background: '#9ca3af' }}
        />
      </div>
    )
  },
}

// ── Data summary helpers ───────────────────────────────────────────────
function nodeDataSummary(type, data) {
  switch (type) {
    case 'send_template':  return data.template_name ? `"${data.template_name}"` : (data.template_id ? `Template #${data.template_id}` : 'No template selected')
    case 'add_tag':
    case 'remove_tag':     return data.tag_name  ? `#${data.tag_name}`   : (data.tag_id   ? `Tag #${data.tag_id}` : '')
    case 'update_status':  return data.status    ? `→ ${data.status}`   : ''
    case 'assign_agent':   return data.user_id   ? `User #${data.user_id}` : ''
    case 'handoff':        return (data.user_id ? `User #${data.user_id}` : 'Auto (routing rules)') + (data.stop ? ' · stop' : '')
    case 'send_payment':   return data.amount ? `₹${data.amount}${data.description ? ` · ${data.description}` : ''}` : 'No amount set'
    case 'send_product':   return data.retailer_id ? `SKU: ${data.retailer_id}` : 'No product selected'
    case 'ai_reply':       return data.system_prompt ? `"${String(data.system_prompt).slice(0, 32)}…"` : 'Default assistant'
    case 'send_flow':      return data.flow_id ? `Flow ${data.flow_id}` : 'No flow set'
    case 'webhook_call':   return data.url       ? data.url.slice(0,40) : 'No URL'
    case 'delay':          return data.value     ? `${data.value} ${data.unit ?? 'seconds'}` : ''
    case 'notify_number':  return data.to ? `→ ${data.to}` : 'No number set'
    case 'send_slots':     return `${data.count ?? 6} slots · ${data.timezone ?? 'Asia/Kolkata'}`
    case 'book_slot':      return data.title ? `"${data.title}"` : 'Intro call'
    default:               return ''
  }
}
