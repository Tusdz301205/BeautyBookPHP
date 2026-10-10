import React from "react";
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer } from "recharts";

export const StylistPerformanceBars = ({ data }) => {
  if (!data || data.length === 0) {
    return (
      <div className="h-64 flex items-center justify-center text-gray-500">
        Không có dữ liệu
      </div>
    );
  }

  return (
    <ResponsiveContainer width="100%" height={300}>
      <BarChart data={data} layout="vertical">
        <CartesianGrid strokeDasharray="3 3" stroke="var(--bb-border)" />
        <XAxis type="number" tick={{ fontSize: 11 }} />
        <YAxis 
          dataKey="name" 
          type="category" 
          width={120} 
          tick={{ fontSize: 11 }}
        />
        <Tooltip 
          formatter={(value, name) => {
            if (name === 'revenue') {
              return [`${(value / 1000000).toFixed(1)}M`, 'Doanh thu'];
            }
            return [value, name];
          }}
        />
        <Bar 
          dataKey="bookings" 
          fill="#C44C6C" 
          name="Lịch hẹn"
          radius={[0, 4, 4, 0]}
        />
        <Bar 
          dataKey="revenue" 
          fill="#FF6B9D" 
          name="Doanh thu (M)"
          radius={[0, 4, 4, 0]}
        />
      </BarChart>
    </ResponsiveContainer>
  );
};

export default StylistPerformanceBars;
