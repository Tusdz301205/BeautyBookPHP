import React from 'react';
// Preserve geometry during a loading replacement, without retaining any data.
// The original DOM tag and attributes remain unchanged.
export default class StableSurface extends React.Component {
 node=React.createRef();
 getSnapshotBeforeUpdate(){
  const node=this.node.current,height=node?.getBoundingClientRect().height||0;
  // Reserve space before React removes the old calendar, so the browser cannot
  // clamp its scroll position during the loading replacement.
  if(node&&this.props.preserveHeight&&height>0){node.style.minHeight=Math.max(height,parseFloat(node.style.minHeight)||0)+'px';node.style.overflowAnchor='none';}
  return height;
 }
 componentDidUpdate(_props,_state,height){
  const node=this.node.current;if(!node)return;
  if(this.props.preserveHeight)return;
  const loading=node.matches('[aria-busy="true"]')||Boolean(node.querySelector('[aria-busy="true"]'))||[...node.querySelectorAll('[role="status"]')].some(el=>/^(Đang tải|Đang cập nhật|Đang làm mới)/.test(el.textContent.trim()));
  if(loading&&height>0){node.style.minHeight=Math.max(height,parseFloat(this.props.style?.minHeight)||0)+'px';node.style.overflowAnchor='none';}
  else {node.style.minHeight=this.props.style?.minHeight??'';node.style.overflowAnchor=this.props.style?.overflowAnchor??'';}
 }
 render(){const {as:Tag='div',children,preserveHeight,...props}=this.props;return <Tag {...props} ref={this.node}>{children}</Tag>;}
}
