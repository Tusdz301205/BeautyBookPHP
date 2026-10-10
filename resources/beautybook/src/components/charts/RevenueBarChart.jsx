import React from "react";
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer } from "recharts";

export const RevenueBarChart = ({ data }) => {
  if (!data || data.length === 0) {
    return (
      <div className="h-64 flex items-center justify-center text-gray-500">
        Không có dữ liệu doanh thu
      </div>
    );
  }

  return (
    <ResponsiveContainer width="100%" height={280}>
      <BarChart data={data}>
        <CartesianGrid strokeDasharray="3 3" stroke="var(--bb-border)" />
        <XAxis dataKey="month" tick={{ fontSize: 11 }} />
        <YAxis tick={{ fontSize: 11 }} />
        <Tooltip 
          formatter={(value, name) => {
            if (name === 'revenue') {
              return [`${value}M VNĐ`, 'Doanh thu'];
            }
            return [value, name];
          }}
        />
        <Bar 
          dataKey="revenue" 
          fill="var(--bb-brand)" 
          radius={[6, 6, 0, 0]}
          name="Doanh thu"
        />
      </BarChart>
    </ResponsiveContainer>
  );
};

export default RevenueBarChart;
