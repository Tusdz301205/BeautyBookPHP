import { useEffect, useState } from 'react';
import { Image as ImageIcon } from 'lucide-react';
import { uploadedMediaUrl } from '../../utils/mediaUrl';

export function HomeMedia({
  src,
  fallbackSrc,
  alt,
  fallbackAlt,
  ratio = '4 / 3',
  label = 'Hình ảnh BeautyBook',
  eager = false,
  className = '',
}) {
  const [loaded, setLoaded] = useState(false);
  const [failed, setFailed] = useState(false);
  const [fallbackActive, setFallbackActive] = useState(false);
  const primaryMedia = typeof src === 'string' ? { src } : (src || {});
  const backupMedia = typeof fallbackSrc === 'string' ? { src: fallbackSrc } : (fallbackSrc || {});
  const usingFallback = fallbackActive || (!primaryMedia.src && Boolean(backupMedia.src));
  const media = usingFallback ? backupMedia : primaryMedia;
  const resolvedSrc = typeof media.src === 'string' && media.src.startsWith('/api/v1/media/') ? uploadedMediaUrl(media.src) : (media.src || '');
  const resolvedAlt = usingFallback
    ? (fallbackAlt ?? media.alt ?? alt ?? '')
    : (alt ?? media.alt ?? '');
  const isIllustration = Boolean(media.illustration);

  useEffect(() => {
    setLoaded(false);
    setFailed(false);
    setFallbackActive(false);
  }, [primaryMedia.src, backupMedia.src]);

  const handleError = () => {
    if (!usingFallback && backupMedia.src) {
      setLoaded(false);
      setFailed(false);
      setFallbackActive(true);
      return;
    }
    setFailed(true);
  };

  const showImage = Boolean(resolvedSrc) && !failed;

  return (
    <figure
      className={`bb-home-media ${showImage ? 'has-image' : 'is-placeholder'} ${loaded ? 'is-loaded' : ''} ${className}`.trim()}
      style={{ aspectRatio: ratio }}
    >
      {showImage ? (
        <img
          src={resolvedSrc}
          srcSet={media.srcSet}
          sizes={media.sizes}
          width={media.width}
          height={media.height}
          alt={resolvedAlt}
          loading={eager ? 'eager' : 'lazy'}
          fetchpriority={eager ? 'high' : 'auto'}
          decoding="async"
          onLoad={() => setLoaded(true)}
          onError={handleError}
        />
      ) : (
        <div className="bb-home-media__placeholder" role="img" aria-label={`${resolvedAlt || label}. Hình ảnh sẽ được cập nhật.`}>
          <span className="bb-home-media__mark" aria-hidden="true"><ImageIcon size={18} strokeWidth={1.7} /></span>
          <span className="bb-home-media__copy">
            <strong>{label}</strong>
            <small>Hình ảnh sẽ được cập nhật</small>
          </span>
        </div>
      )}
      {showImage && loaded && isIllustration ? <span className="bb-home-media__illustration">Ảnh minh họa</span> : null}
      {showImage && !loaded ? <span className="bb-home-media__loading" aria-hidden="true" /> : null}
    </figure>
  );
}
