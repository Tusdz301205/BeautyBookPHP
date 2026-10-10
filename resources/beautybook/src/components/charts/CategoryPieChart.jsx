import React from "react";
import { PieChart, Pie, Cell, Tooltip, ResponsiveContainer } from "recharts";

const PIE_COLORS = ["#D6597B", "#C99A4E", "#6E8A64", "#8E7CB8", "#7C93B8"];

export const CategoryPieChart = ({ data }) => {
  const total = data.reduce((sum, item) => sum + Number(item.value || 0), 0);

  return (
    <div className="grid min-h-[260px] items-center gap-4 sm:grid-cols-[minmax(0,1fr)_minmax(150px,0.8fr)]">
      <div className="relative h-[230px] min-w-0">
        <ResponsiveContainer width="100%" height="100%">
          <PieChart>
            <Pie data={data} dataKey="value" nameKey="name" innerRadius={52} outerRadius={86} paddingAngle={2}>
              {data.map((item, index) => <Cell key={item.name} fill={PIE_COLORS[index % PIE_COLORS.length]} />)}
            </Pie>
            <Tooltip formatter={(value) => Number(value).toLocaleString('vi-VN')} />
          </PieChart>
        </ResponsiveContainer>
        <div className="pointer-events-none absolute inset-0 grid place-content-center text-center">
          <strong className="text-xl text-[var(--bb-ink)]">{total.toLocaleString('vi-VN')}</strong>
          <span className="text-xs text-[var(--bb-muted)]">lịch hẹn</span>
        </div>
      </div>
      <ul className="max-h-[230px] space-y-2 overflow-y-auto pr-1 text-sm" aria-label="Chú thích nhóm dịch vụ">
        {data.map((item, index) => (
          <li key={item.name} className="flex items-center gap-2">
            <span className="h-2.5 w-2.5 shrink-0 rounded-sm" style={{ backgroundColor: PIE_COLORS[index % PIE_COLORS.length] }} />
            <span className="min-w-0 flex-1 truncate text-[var(--bb-muted)]" title={item.name}>{item.name}</span>
            <strong className="shrink-0 text-[var(--bb-ink)]">{Number(item.value).toLocaleString('vi-VN')}</strong>
          </li>
        ))}
      </ul>
    </div>
  );
};

export default CategoryPieChart;
