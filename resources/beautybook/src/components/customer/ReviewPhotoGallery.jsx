import React, { useEffect, useState } from 'react';
import { mediaApi } from '../../api/apiClient';
import { useAuthStore } from '../../store/authStore';
import { uploadedMediaUrl } from '../../utils/mediaUrl';

function ReviewPhoto({ photo, index, isPublic }) {
  const actor = useAuthStore((state) => state.user?.id);
  const [loaded, setLoaded] = useState(null);
  const [broken, setBroken] = useState(false);

  useEffect(() => {
    if (isPublic || !actor) return undefined;
    let active = true;
    let url;
    mediaApi.download(photo.mediaId).then((blob) => {
      if (!active) return;
      if (!['image/jpeg', 'image/png', 'image/webp'].includes(blob.type)) throw new Error();
      url = URL.createObjectURL(blob);
      setLoaded({ actor, url });
    }).catch(() => {
      if (active) setLoaded({ actor, error: true });
    });
    return () => {
      active = false;
      if (url) URL.revokeObjectURL(url);
    };
  }, [actor, isPublic, photo.mediaId]);

  const current = actor && loaded?.actor === actor ? loaded : null;
  const url = isPublic ? uploadedMediaUrl(photo.contentUrl) : current?.url;
  if (broken || current?.error) return <span className="text-xs text-[var(--bb-muted)]">Ảnh chưa tải được</span>;
  if (!url) return <span className="aspect-square rounded-lg bg-[var(--bb-surface-subtle)]" aria-label="Đang tải ảnh trải nghiệm" />;
  return (
    <a href={url} target="_blank" rel="noreferrer" className="block overflow-hidden rounded-lg focus-visible:outline focus-visible:outline-2" aria-label={`Mở ảnh trải nghiệm ${index + 1}`}>
      <img src={url} alt={`Ảnh trải nghiệm ${index + 1}`} loading="lazy" onError={() => setBroken(true)} className="aspect-square w-full object-cover" />
    </a>
  );
}

export function ReviewPhotoGallery({ photos, isPublic = false }) {
  if (!photos?.length) return null;
  return (
    <div role="group" aria-label="Ảnh trải nghiệm" className="mt-3 grid max-w-md grid-cols-3 gap-2 sm:grid-cols-5">
      {photos.map((photo, index) => <ReviewPhoto key={photo.mediaId} photo={photo} index={index} isPublic={isPublic} />)}
    </div>
  );
}
