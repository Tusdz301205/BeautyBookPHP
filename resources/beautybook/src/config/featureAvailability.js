// Finance is available by default; deployments can explicitly hide the workspace.
export const FINANCE_WORKSPACE_ENABLED = import.meta.env?.VITE_FINANCE_WORKSPACE !== "false";
