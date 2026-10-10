import React from "react";
import ReactDOM from "react-dom/client";
import App from "./App.jsx";
import { AppErrorBoundary } from "./components/layout/AppErrorBoundary.jsx";
// Nếu project dùng Tailwind CSS, import file index.css có @tailwind directives ở đây, ví dụ:
import "./index.css";
import "./styles/theme.css";
import "./styles/mobile-booking.css";
import "./styles/mobile-public.css";

ReactDOM.createRoot(document.getElementById("root")).render(
  <React.StrictMode>
    <AppErrorBoundary><App /></AppErrorBoundary>
  </React.StrictMode>
);
