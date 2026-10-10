import React from "react";
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer } from "recharts";

export const TopSalonsBookingBarChart = ({ data }) => (
  <ResponsiveContainer width="100%" height={240}>
    <BarChart data={data}>
      <CartesianGrid strokeDasharray="3 3" stroke="var(--bb-border)" />
      <XAxis dataKey="name" tick={{ fontSize: 9 }} />
      <YAxis tick={{ fontSize: 11 }} />
      <Tooltip />
      <Bar dataKey="bookings" fill="var(--gold)" radius={[6,6,0,0]} />
    </BarChart>
  </ResponsiveContainer>
);

export default TopSalonsBookingBarChart;
