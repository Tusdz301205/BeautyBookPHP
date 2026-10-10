/** Deliver only the current request, including when earlier failures arrive late. */
export function createLatestRequest() {
  let version = 0;
  return {
    invalidate() { version += 1; },
    async run(load, onSuccess, onError) {
      const current = ++version;
      try {
        const data = await load();
        if (current === version) onSuccess(data);
      } catch (error) {
        if (current === version) onError(error);
      }
    },
  };
}
