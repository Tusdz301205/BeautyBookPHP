import React from "react";
import ReactDOM from "react-dom/client";
import App from "./App.jsx";
import { AppErrorBoundary } from "./components/layout/AppErrorBoundary.jsx";
import "./index.css";
import "./styles/theme.css";
import "./styles/mobile-booking.css";
import "./styles/mobile-public.css";
import "./styles/glowme-clone.css";
import "./styles/ui-refinements.css";
import "./styles/fe-refinements.css";

ReactDOM.createRoot(document.getElementById("root")).render(
  <React.StrictMode>
    <AppErrorBoundary><App /></AppErrorBoundary>
  </React.StrictMode>
);
