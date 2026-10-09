/**
 * FlowCanvas — @xyflow/react canvas for building TripSarthi flows.
 *
 * Save logic:
 *   buildGraphPayload(nodes, edges) maps @xyflow state → {nodes, edges} graph.
 *   sourceHandle values come from the EDGE data, not from any checkbox flag.
 *   A window_closed edge appears in the graph IF AND ONLY IF the user
 *   drew a connection from that handle. The engine's findEdge() reads these
 *   exact strings — no translation layer.
 */
import { useCallback, useRef } from 'react'
import {
  ReactFlow, ReactFlowProvider,
  useNodesState, useEdgesState, addEdge,
  Background, Controls, MiniMap,
  useReactFlow,
} from '@xyflow/react'
import '@xyflow/react/dist/style.css'
import { Lock, Save } from 'lucide-react'
import { nodeTypes, NODE_META } from './FlowNodeTypes'
import NodeConfigPanel from './NodeConfigPanel'

// ── Palette definition ─────────────────────────────────────────────────
const PALETTE_GROUPS = [
  {
    label: 'TRIGGERS',
    color: '#16a34a',
    nodes: ['lead_created','tag_added','form_submitted','meta_lead_received','google_lead_received','keyword_reply','inbound_message','deal_created','deal_stage_changed','deal_won','deal_lost','ticket_created','ticket_resolved','task_due'],
  },
  {
    label: 'ACTIONS',
    color: '#08569f',
    nodes: ['send_template','send_freeform','send_media','add_tag','remove_tag','update_status','assign_agent','webhook_call','send_interactive','send_email','create_task','update_field','create_deal','create_ticket'],
  },
  {
    label: 'CONTROL',
    color: '#d97706',
    nodes: ['delay','condition','window_check'],
  },
]

// Default data for newly dropped nodes
const DEFAULT_DATA = {
  send_template:    { template_id: 0, variable_mapping: '{}', variable_defaults: '{}' },
  send_freeform:    { content: '' },
  send_media:       { type: 'image', url: '', caption: '' },
  add_tag:          { tag_id: 0 },
  remove_tag:       { tag_id: 0 },
  update_status:    { status: 'contacted' },
  assign_agent:     { user_id: 0 },
  webhook_call:     { url: '' },
  send_interactive: {
    interactive_type: 'button',
    header: '',
    body: 'Choose an option:',
    footer: '',
    buttons: [{id:'btn_yes',title:'Yes ✅'},{id:'btn_no',title:'No ❌'}],
    cta_text: 'Visit Website',
    cta_url: '',
    button_text: 'View Options',
    sections: [{title:'Options',rows:[{id:'row_1',title:'Item 1',description:''},{id:'row_2',title:'Item 2',description:''}]}],
  },
  delay:            { value: 1, unit: 'minutes' },
  condition:        { type: 'contact_field', field: 'status', operator: 'equals', value: '' },
  window_check:     {},
  // CRM triggers / actions (Phase C)
  deal_stage_changed: { stage_id: 0 },
  send_email:       { email_template_id: 0, subject: '' },
  create_task:      { title: 'Follow up', type: 'todo', priority: 'medium', due_in_days: 1 },
  update_field:     { field: 'lifecycle_stage', value: 'customer' },
  create_deal:      { title: '', value_amount: 0 },
  create_ticket:    { subject: 'Support request', priority: 'medium' },
}

let nodeCounter = 0
function makeId() { return `node_${Date.now()}_${++nodeCounter}` }

// ── Save payload builder ────────────────────────────────────────────────
// This is the single source of truth for the graph saved to the API.
// window_closed edges appear here IF AND ONLY IF the user drew them.
export function buildGraphPayload(nodes, edges) {
  return {
    nodes: nodes.map(n => ({
      id:       n.id,
      type:     n.type,
      data:     { ...n.data },
      position: { x: Math.round(n.position.x), y: Math.round(n.position.y) },
    })),
    edges: edges.map(e => ({
      id:           e.id,
      source:       e.source,
      target:       e.target,
      // Default to 'next' for single-output nodes (TriggerNode, ActionNode)
      sourceHandle: e.sourceHandle ?? 'next',
    })),
  }
}

// ── Inner canvas (needs ReactFlowProvider above it) ────────────────────
function CanvasInner({ readOnly, selectedNode, setSelectedNode, onNodesChange, onEdgesChange,
                       nodes, edges, setEdges, setNodes, tags, users }) {
  const reactFlow = useReactFlow()
  const rfWrapper = useRef(null)

  const onConnect = useCallback(
    (params) => {
      // Preserve the source handle — this is how window_closed, true/false etc. are saved
      setEdges(eds => addEdge({
        ...params,
        id: `e_${Date.now()}`,
        animated: params.sourceHandle === 'window_closed',
        style: params.sourceHandle === 'window_closed' ? { stroke: '#f97316', strokeDasharray: '6' }
             : params.sourceHandle === 'true'  ? { stroke: '#16a34a' }
             : params.sourceHandle === 'false' ? { stroke: '#dc2626' }
             : params.sourceHandle === 'open'  ? { stroke: '#3b82f6' }
             : params.sourceHandle === 'closed' ? { stroke: '#9ca3af' }
             : {},
      }, eds))
    },
    [setEdges]
  )

  // Drag-over: allow drop
  function onDragOver(e) { e.preventDefault(); e.dataTransfer.dropEffect = 'move' }

  // Drop: create node at canvas position
  function onDrop(e) {
    e.preventDefault()
    if (readOnly) return
    const nodeType = e.dataTransfer.getData('nodeType')
    if (!nodeType) return

    const bounds = rfWrapper.current?.getBoundingClientRect()
    const pos    = reactFlow.screenToFlowPosition({
      x: e.clientX - (bounds?.left ?? 0),
      y: e.clientY - (bounds?.top  ?? 0),
    })

    const newNode = {
      id:       makeId(),
      type:     nodeType,
      position: pos,
      data:     { ...(DEFAULT_DATA[nodeType] ?? {}) },
    }
    setNodes(nds => [...nds, newNode])
  }

  // Node click → select for config panel
  function onNodeClick(_, node) { setSelectedNode(node) }
  function onPaneClick()       { setSelectedNode(null) }

  // Update selected node's data when config panel changes it
  function onDataChange(newData) {
    if (!selectedNode) return
    setNodes(nds => nds.map(n => n.id === selectedNode.id ? { ...n, data: newData } : n))
    setSelectedNode(prev => ({ ...prev, data: newData }))
  }

  return (
    <div style={{ display:'flex', flex:1, overflow:'hidden' }}>
      {/* Palette */}
      <div style={{
        width: 160, background:'#1a1a2e', overflowY:'auto',
        padding:'12px 0', flexShrink:0, userSelect:'none',
      }}>
        {PALETTE_GROUPS.map(group => (
          <div key={group.label}>
            <div style={{ color:'#475569', fontSize:10, fontWeight:700, padding:'8px 12px 4px', letterSpacing:1 }}>
              {group.label}
            </div>
            {group.nodes.map(nt => {
              const meta = NODE_META[nt]
              const Icon = meta?.icon
              return (
                <div
                  key={nt}
                  draggable={!readOnly}
                  onDragStart={e => { e.dataTransfer.setData('nodeType', nt); e.dataTransfer.effectAllowed = 'move' }}
                  style={{
                    display:'flex', alignItems:'center', gap:8,
                    padding:'6px 12px', cursor: readOnly ? 'default' : 'grab',
                    color:'#cbd5e1', fontSize:12,
                    borderLeft: `3px solid ${group.color}`,
                    marginBottom:1,
                    opacity: readOnly ? 0.5 : 1,
                  }}
                  title={meta?.desc}
                >
                  {Icon && <Icon size={14} strokeWidth={1.8} style={{ flexShrink: 0 }} />}
                  <span>{meta?.label}</span>
                </div>
              )
            })}
          </div>
        ))}
      </div>

      {/* Canvas */}
      <div ref={rfWrapper} style={{ flex:1, position:'relative' }} onDragOver={onDragOver} onDrop={onDrop}>
        {readOnly && (
          <div style={{
            position:'absolute', top:0, left:0, right:0,
            background:'rgba(251,146,60,0.12)', borderBottom:'2px solid #fb923c',
            padding:'6px 12px', zIndex:10, fontSize:12, color:'#c2410c',
            display:'flex', alignItems:'center', gap:8
          }}>
            <Lock size={13} strokeWidth={2} /> Flow is <strong>active</strong> — pause it before editing.
          </div>
        )}
        <ReactFlow
          nodes={nodes}
          edges={edges}
          nodeTypes={nodeTypes}
          onNodesChange={readOnly ? undefined : onNodesChange}
          onEdgesChange={readOnly ? undefined : onEdgesChange}
          onConnect={readOnly ? undefined : onConnect}
          onNodeClick={onNodeClick}
          onPaneClick={onPaneClick}
          deleteKeyCode={readOnly ? null : 'Backspace'}
          fitView
        >
          <Background />
          <Controls />
          <MiniMap nodeColor={n => NODE_META[n.type]?.group === 'trigger' ? '#16a34a' : NODE_META[n.type]?.group === 'control' ? '#d97706' : '#08569f'} />
        </ReactFlow>
      </div>

      {/* Config panel */}
      <div style={{
        width: 260, background:'#fff', borderLeft:'1px solid #e5e7eb',
        overflowY:'auto', flexShrink:0,
      }}>
        <div style={{ padding:'10px 12px', borderBottom:'1px solid #e5e7eb', fontSize:12, fontWeight:600, color:'#374151' }}>
          {selectedNode ? `Configure: ${NODE_META[selectedNode.type]?.label ?? selectedNode.type}` : 'Node config'}
        </div>
        <NodeConfigPanel
          node={selectedNode}
          onChange={onDataChange}
          readOnly={readOnly}
          tags={tags}
          users={users}
        />
      </div>
    </div>
  )
}

// ── Exported canvas with provider ──────────────────────────────────────
export default function FlowCanvas({ flow, onSave, readOnly, selectedNode, setSelectedNode, tags, users }) {
  // Parse initial graph from flow.graph JSON
  const parsed = (() => {
    try { return JSON.parse(flow?.graph ?? '{}') } catch { return {} }
  })()

  // Nodes from seeders/imports may lack position — @xyflow/react crashes the
  // whole tree (StoreUpdater reads position.x) without it. Backfill a simple
  // left-to-right layout for any node missing one.
  const initialNodes = (parsed.nodes ?? []).map((n, i) => ({
    ...n,
    position: n.position ?? { x: 80 + i * 240, y: 120 },
    // Unrecognised types fall back to the "Unsupported node" renderer, which
    // only sees the resolved type — keep the original so it can name it.
    data: nodeTypes[n.type] ? n.data : { ...(n.data ?? {}), __rawType: n.type },
  }))

  const [nodes, setNodes, onNodesChange] = useNodesState(initialNodes)
  const [edges, setEdges, onEdgesChange] = useEdgesState(parsed.edges ?? [])

  // Expose save to parent via stable handler
  // buildGraphPayload reads from edges state — sourceHandles come from edges, not flags
  function handleSave() {
    onSave(buildGraphPayload(nodes, edges))
  }

  return (
    <div style={{ display:'flex', flexDirection:'column', height:'100%' }}>
      <div style={{ padding:'6px 12px', background:'#f9fafb', borderBottom:'1px solid #e5e7eb', display:'flex', justifyContent:'flex-end' }}>
        <button
          onClick={handleSave}
          disabled={readOnly}
          style={{
            padding:'5px 14px', background: readOnly ? '#e5e7eb' : '#08569f',
            color: readOnly ? '#9ca3af' : '#fff', border:'none', borderRadius:6,
            fontSize:12, fontWeight:600, cursor: readOnly ? 'default' : 'pointer',
            display:'inline-flex', alignItems:'center', gap:6,
          }}
        >
          <Save size={13} strokeWidth={2} /> Save graph
        </button>
      </div>
      <div style={{ flex:1, overflow:'hidden', display:'flex' }}>
        <ReactFlowProvider>
          <CanvasInner
            readOnly={readOnly}
            selectedNode={selectedNode}
            setSelectedNode={setSelectedNode}
            nodes={nodes}
            edges={edges}
            setNodes={setNodes}
            setEdges={setEdges}
            onNodesChange={onNodesChange}
            onEdgesChange={onEdgesChange}
            tags={tags}
            users={users}
          />
        </ReactFlowProvider>
      </div>
    </div>
  )
}
