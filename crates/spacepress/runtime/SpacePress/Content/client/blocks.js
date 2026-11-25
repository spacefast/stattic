// The editor half of components in Markdown (SpacePress\Content\Blocks) for components that take
// `children`: its props are fields in the block's sidebar and its content is inner blocks. A
// component without `children` needs nothing here: core's `autoRegister` support registers it.
// No build step: it uses the editor's own globals.
(function (wp) {
	const { registerBlockType, getBlockType } = wp.blocks;
	const { createElement: el, Fragment } = wp.element;
	const { InnerBlocks, InspectorControls, useBlockProps } = wp.blockEditor;
	const { PanelBody, TextControl, SelectControl, ToggleControl } = wp.components;

	function field(key, spec, value, set) {
		if (spec.enum) {
			const options = [{ label: '', value: '' }].concat(spec.enum.map((v) => ({ label: v, value: v })));
			return el(SelectControl, { key, label: key, value: value ?? '', options, onChange: (v) => set(v === '' ? undefined : v) });
		}
		if (spec.type === 'boolean') {
			return el(ToggleControl, { key, label: key, checked: !!value, onChange: (v) => set(v) });
		}
		if (spec.type === 'number') {
			return el(TextControl, { key, label: key, type: 'number', value: value ?? '', onChange: (v) => set(v === '' ? undefined : Number(v)) });
		}
		return el(TextControl, { key, label: key, value: value ?? '', onChange: (v) => set(v === '' ? undefined : v) });
	}

	for (const [name, spec] of Object.entries(window.spContentBlocks || {})) {
		if (getBlockType(name)) {
			continue;
		}
		registerBlockType(name, {
			apiVersion: 3,
			title: spec.tag,
			category: 'design',
			attributes: spec.attributes,
			edit: function Edit({ attributes, setAttributes }) {
				const props = useBlockProps();
				const fields = Object.entries(spec.attributes).map(([key, attribute]) => field(key, attribute, attributes[key], (v) => setAttributes({ [key]: v })));
				const panel = el(InspectorControls, null, el(PanelBody, { title: spec.tag }, fields));
				return el(Fragment, null, panel, el('div', props, el('strong', null, spec.tag), el(InnerBlocks)));
			},
			// A dynamic block: the server renders it. Only its inner blocks are saved.
			save: () => el(InnerBlocks.Content),
		});
	}
})(window.wp);
