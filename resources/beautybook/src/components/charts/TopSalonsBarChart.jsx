import React from "react";
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer } from "recharts";

export const TopSalonsBarChart = ({ data }) => (
  <ResponsiveContainer width="100%" height={260}>
    <BarChart data={data} layout="vertical" margin={{ left: 20 }}>
      <CartesianGrid strokeDasharray="3 3" stroke="var(--bb-border)" />
      <XAxis type="number" tick={{ fontSize: 11 }} />
      <YAxis type="category" dataKey="name" tick={{ fontSize: 11 }} width={100} />
      <Tooltip />
      <Bar dataKey="bookings" fill="var(--gold)" radius={[0,6,6,0]} />
    </BarChart>
  </ResponsiveContainer>
);

export default TopSalonsBarChart;
