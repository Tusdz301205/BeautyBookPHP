import React from "react";

export function CategoryFilterChips({ categories, active, onChange }) {
  return (
    <div className="flex flex-wrap gap-2 mb-5">
      {categories.map(c => (
        <button
          key={c}
          onClick={() => onChange(c)}
          className="px-3 py-1.5 rounded-full text-xs font-semibold"
          style={{ background: active === c ? "var(--bb-brand)" : "var(--bb-surface)", color: active === c ? "var(--color-accent-ink)" : "var(--bb-ink)", border: "1px solid var(--bb-border)" }}
        >
          {c}
        </button>
      ))}
    </div>
  );
}

export default CategoryFilterChips;
