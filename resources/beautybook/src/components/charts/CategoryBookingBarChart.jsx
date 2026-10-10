import React from "react";
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer } from "recharts";

export const CategoryBookingBarChart = ({ data }) => (
  <ResponsiveContainer width="100%" height={240}>
    <BarChart data={data}>
      <CartesianGrid strokeDasharray="3 3" stroke="var(--bb-border)" />
      <XAxis dataKey="name" tick={{ fontSize: 10 }} />
      <YAxis tick={{ fontSize: 11 }} />
      <Tooltip />
      <Bar dataKey="value" fill="var(--bb-brand)" radius={[6,6,0,0]} />
    </BarChart>
  </ResponsiveContainer>
);

export default CategoryBookingBarChart;
