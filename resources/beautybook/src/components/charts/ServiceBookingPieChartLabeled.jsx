import React from "react";
import { PieChart, Pie, Cell, Tooltip, ResponsiveContainer, Legend } from "recharts";

const COLORS = ['#FF6B9D', '#C44C6C', '#E8779A', '#F4A0B5', '#FDAEC1'];

export const ServiceBookingPieChartLabeled = ({ data }) => {
  if (!data || data.length === 0) {
    return (
      <div className="h-64 flex items-center justify-center text-gray-500">
        Không có dữ liệu
      </div>
    );
  }

  return (
    <ResponsiveContainer width="100%" height={280}>
      <PieChart>
        <Pie
          data={data}
          cx="50%"
          cy="50%"
          innerRadius={50}
          outerRadius={100}
          paddingAngle={2}
          dataKey="value"
          label={({ name, percent }) => `${name}: ${(percent * 100).toFixed(0)}%`}
          labelLine={true}
        >
          {data.map((entry, index) => (
            <Cell key={`cell-${index}`} fill={COLORS[index % COLORS.length]} />
          ))}
        </Pie>
        <Tooltip 
          formatter={(value, name) => [`${value} lịch hẹn`, name]}
        />
        <Legend 
          verticalAlign="bottom" 
          height={36}
          formatter={(value) => <span className="text-sm">{value}</span>}
        />
      </PieChart>
    </ResponsiveContainer>
  );
};

export default ServiceBookingPieChartLabeled;
