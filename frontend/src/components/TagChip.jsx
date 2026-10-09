export default function TagChip({ tag, onRemove }) {
  return (
    <span className="tag-chip" style={{ background: tag.color ?? '#0a6cc4' }}>
      {tag.name}
      {onRemove && (
        <button
          className="tag-chip-remove"
          onClick={() => onRemove(tag.id)}
          title={`Remove ${tag.name}`}>
          ×
        </button>
      )}
    </span>
  )
}
