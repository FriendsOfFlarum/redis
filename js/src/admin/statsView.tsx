import app from 'flarum/admin/app';
import type Mithril from 'mithril';

/**
 * A single labeled stat tile. `labelKey` is the full translator key so every
 * label greps to its use site; `sub` carries context such as a percentage.
 */
export function statTile(key: string, labelKey: string, value: Mithril.Children, sub?: Mithril.Children, href?: string): Mithril.Children {
  const content = [
    <div className="RedisWidget-tileLabel">{app.translator.trans(labelKey)}</div>,
    <div className="RedisWidget-tileValue">{value ?? '0'}</div>,
    sub && <div className="RedisWidget-tileSub">{sub}</div>,
  ];

  const className = `RedisWidget-tile RedisWidget-tile--${key}`;

  return href ? (
    <a className={className} href={href} target="_blank" rel="noopener noreferrer">
      {content}
    </a>
  ) : (
    <div className={className}>{content}</div>
  );
}
