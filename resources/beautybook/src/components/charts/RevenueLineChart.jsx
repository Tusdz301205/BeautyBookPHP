import React from "react";
import { LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer } from "recharts";

export const RevenueLineChart = ({ data }) => (
  <ResponsiveContainer width="100%" height={260}>
    <LineChart data={data}>
      <CartesianGrid strokeDasharray="3 3" stroke="var(--bb-border)" />
      <XAxis dataKey="month" tick={{ fontSize: 12 }} />
      <YAxis tick={{ fontSize: 12 }} />
      <Tooltip />
      <Line type="monotone" dataKey="revenue" stroke="var(--bb-brand)" strokeWidth={3} dot={{ r: 4 }} />
    </LineChart>
  </ResponsiveContainer>
);

export default RevenueLineChart;
