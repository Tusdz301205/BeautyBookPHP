import React from 'react';

// Lazy route imports can fail offline or after a deployment replaces chunks.
// Keep an actionable page instead of unmounting the whole application or
// automatically reloading a document that cannot be fetched while offline.
export class AppErrorBoundary extends React.Component {
  state = { failed: false, online: typeof navigator === 'undefined' || navigator.onLine };

  static getDerivedStateFromError() {
    return { failed: true };
  }

  updateConnection = () => this.setState({ online: navigator.onLine });

  componentDidMount() {
    window.addEventListener('online', this.updateConnection);
    window.addEventListener('offline', this.updateConnection);
  }

  componentWillUnmount() {
    window.removeEventListener('online', this.updateConnection);
    window.removeEventListener('offline', this.updateConnection);
  }

  render() {
    if (!this.state.failed) return this.props.children;
    return (
      <main className="mx-auto flex min-h-screen w-full max-w-xl flex-col justify-center gap-4 px-6 py-10">
        <p className="font-semibold text-pink-700">BeautyBook</p>
        <h1 className="text-2xl font-bold">{this.state.online ? 'Chưa tải được trang' : 'Bạn đang mất kết nối'}</h1>
        <p role="status" className="text-slate-600">
          {this.state.online
            ? 'Vui lòng tải lại trang để tiếp tục.'
            : 'Kết nối lại Internet, sau đó tải lại trang để tiếp tục.'}
        </p>
        <button
          type="button"
          disabled={!this.state.online}
          onClick={() => window.location.reload()}
          className="min-h-11 self-start rounded-lg bg-pink-700 px-5 py-3 font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50"
        >Tải lại trang</button>
      </main>
    );
  }
}
