<?php
namespace Apple_Exporter\Builders;

use \Apple_Exporter\Component_Factory as Component_Factory;
use \Apple_Exporter\Components\Component as Component;

/**
 * @since 0.4.0
 */
class Components extends Builder {

	/**
	 * Builds an array with all the components of this WordPress content.
	 *
	 * @return array
	 * @access protected
	 */
	protected function build() {
		$components = $this->meta_components();
		foreach ( $this->split_into_components() as $component ) {
			$components[] = $component->to_array();
		}
		return $this->group_body_components( $components );
	}

	/**
	 * Given an array of components in array format, group all the elements of
	 * role 'body'. Ignore body elements that have an ID, as they are used for
	 * anchoring.
	 *
	 * Grouping body like this allows the Apple Format interpreter to render
	 * proper paragraph spacing.
	 *
	 * @since 0.6.0
	 * @param array $components
	 * @return array
	 * @access private
	 */
	private function group_body_components( $components ) {
		$new_components = array();
		$body_collector = null;

		$i   = 0;
		$len = count( $components );
		while( $i < $len ) {
			$component = $components[ $i ];

			// If the component is not body, no need to group, just add.
			if ( 'body' != $component['role'] ) {
				if ( ! is_null( $body_collector ) ) {
					$body_collector['text'] = $body_collector['text'];
					$new_components[] = $body_collector;
					$body_collector   = null;
				}

				$new_components[] = $component;
				$i++;
				continue;
			}

			// If the component is a body, test if it is an anchor target. For
			// grouping an anchor target body several things need to happen:
			if ( isset( $component['identifier'] )               // The FIRST component must be an anchor target
				&& isset( $components[ $i + 1 ]['anchor'] )        // The SECOND must be the component to be anchored
				&& isset( $components[ $i + 2 ]['role'] )
				&& 'body' == $components[ $i + 2 ]['role']        // The THIRD must be a body component
				&& !isset( $components[ $i + 2 ]['identifier'] ) ) // which must not be an anchor target for another component
			{
				// Collect
				if ( ! is_null( $body_collector ) ) {
					$body_collector['text'] = $body_collector['text'];
					$new_components[] = $body_collector;
					$body_collector   = null;
				}

				$new_components[] = $components[ $i + 1 ];
				$body_collector   = $component;
				$body_collector['text'] .= $components[ $i + 2 ]['text'];

				$i += 3;
				continue;
			}

			// Another case for anchor target grouping is when the component was anchored
			// to the next element rather than the previous one, in that case:
			if ( isset( $component['identifier'] )               // The FIRST component must be an anchor target
				&& isset( $components[ $i + 1 ]['role'] )
				&& 'body' == $components[ $i + 1 ]['role']        // The SECOND must be a body component
				&& !isset( $components[ $i + 1 ]['identifier'] ) ) // which must not be an anchor target for another component
			{
				// Collect
				if ( ! is_null( $body_collector ) ) {
					$body_collector['text'] = $body_collector['text'];
					$new_components[] = $body_collector;
					$body_collector   = null;
				}

				$body_collector = $component;
				$body_collector['text'] .= $components[ $i + 1 ]['text'];

				$i += 2;
				continue;
			}

			// If the component was an anchor target but failed to match the
			// requirements for grouping, just add it, don't group it.
			if ( isset( $component['identifier'] ) ) {
				if ( ! is_null( $body_collector ) ) {
					$body_collector['text'] = $body_collector['text'];
					$new_components[] = $body_collector;
					$body_collector   = null;
				}

				$new_components[] = $component;
			} else {
				// The component is not an anchor target, just collect.
				if ( is_null( $body_collector ) ) {
					$body_collector = $component;
				} else {
					$body_collector['text'] .= $component['text'];
				}
			}

			$i++;
		}

		// Make a final check for the body collector, as it might not be empty
		if ( ! is_null( $body_collector ) ) {
			$body_collector['text'] = $body_collector['text'];
			$new_components[] = $body_collector;
		}

		// Trim all body components before returning
		foreach ( $new_components as $i => $component ) {
			if ( 'body' == $component['role'] ) {
				$new_components[ $i ]['text'] = trim( $new_components[ $i ]['text'] );
			}
		}

		return $new_components;
	}

	/**
	 * Meta components are those which were not created from HTML, instead, they
	 * contain only text. This text is normally created from the article
	 * metadata.
	 *
	 * @return array
	 * @access private
	 */
	private function meta_components() {
		$components = array();

		// Title
		if ( $this->content_title() ) {
			$components[] = $this->get_component_from_shortname( 'title', $this->content_title() )->to_array();
		}

		// The content's cover is optional. In WordPress, it's a post's thumbnail
		// or featured image.
		if ( $this->content_cover() ) {
			$components[] = $this->get_component_from_shortname( 'cover', $this->content_cover() )->to_array();
		}

		// Add byline
		if ( $this->content_byline() ) {
			$components[] = $this->get_component_from_shortname( 'byline', $this->content_byline() )->to_array();
		}

		return $components;
	}

	/**
	 * Split components from the source WordPress content.
	 *
	 * @return array
	 * @access private
	 */
	private function split_into_components() {
		// Loop though the first-level nodes of the body element. Components
		// might include child-components, like an Cover and Image.
		$result = array();
		foreach ( $this->content_nodes() as $node ) {
			$components = $this->get_components_from_node( $node );
			$result     = array_merge( $result, $components );
		}

		// Process the result some more. It gets passed by reference for efficiency.
		// It's not like it's a big memory save but still relevant.
		// FIXME: Maybe this could have been done in a better way?
		$this->add_advertisement_if_needed( $result );
		$this->anchor_components( $result );
		$this->add_pullquote_if_needed( $result );

		return $result;
	}

	/**
	 * Add an iAd unit if required.
	 *
	 * @access private
	 */
	private function add_advertisement_if_needed( &$components ) {
		if ( 'yes' != $this->get_setting( 'enable_advertisement' ) ) {
			return;
		}

		// Always position the advertisement in the middle
		$index     = ceil( count( $components ) / 2 );
		$component = $this->get_component_from_shortname( 'advertisement' );

		// Add component in position
		array_splice( $components, $index, 0, array( $component ) );
	}

	/**
	 * Anchor components that are marked as can_be_anchor_target.
	 *
	 * @param array &$components
	 * @access private
	 */
	private function anchor_components( &$components ) {
		$len = count( $components );

		for ( $i = 0; $i < $len; $i++ ) {
			$component = $components[ $i ];

			if ( $component->is_anchor_target() || Component::ANCHOR_NONE == $component->get_anchor_position() ) {
				continue;
			}

			// Anchor this component to previous component. If there's no previous
			// component available, try with the next one.
			if ( empty( $components[ $i - 1 ] ) ) {
				// Check whether this is the only component of the article, if it is,
				// just ignore anchoring.
				if ( empty( $components[ $i + 1 ] ) ) {
					return;
				} else {
					$target_component = $components[ $i + 1 ];
				}
			} else {
				$target_component = $components[ $i - 1 ];
			}

			// Skip advertisement elements, they must span all width. If the previous
			// element is an ad, use next instead. If the element is already
			// anchoring something, also skip.
			$counter = 1;
			$len     = count( $components );
			while ( ! $target_component->can_be_anchor_target() && $i + $counter < $len ) {
				$target_component = $components[ $i + $counter ];
				$counter++;
			}

			$this->anchor_together( $component, $target_component );
		}
	}

	/**
	 * Given two components, anchor the first one to the second.
	 *
	 * @param Component $component
	 * @param Component $target_component
	 * @access private
	 */
	private function anchor_together( $component, $target_component ) {
		if ( $target_component->is_anchor_target() ) {
			return;
		}

		$component->set_json( 'anchor', array(
			'targetComponentIdentifier' => $target_component->uid(),
			'targetAnchorPosition'      => 'center',
			'rangeStart'                => 0,
			'rangeLength'               => 1,
		) );

		// Given $component, find out the opposite position.
		$other_position = null;
		if ( Component::ANCHOR_AUTO == $component->get_anchor_position() ) {
			$other_position = 'left' == $this->get_setting( 'body_orientation' ) ? Component::ANCHOR_LEFT : Component::ANCHOR_RIGHT;
		} else {
			$other_position = Component::ANCHOR_LEFT == $component->get_anchor_position() ? Component::ANCHOR_RIGHT : Component::ANCHOR_LEFT;
		}
		$target_component->set_anchor_position( $other_position );
		// The anchor method adds the required layout, thus making the actual
		// anchoring. This must be called after using the UID, because we need to
		// distinguish target components from anchor ones and components with
		// UIDs are always anchor targets.
		$target_component->anchor();
		$component->anchor();
	}

	/**
	 * Add a pullquote component if needed.
	 *
	 * @param array &$components
	 * @access private
	 */
	private function add_pullquote_if_needed( &$components ) {
		// Must we add a pullquote?
		$pullquote          = $this->content_setting( 'pullquote' );
		$pullquote_position = $this->content_setting( 'pullquote_position' );
		$valid_positions    = array( 'top', 'middle', 'bottom' );

		if ( empty( $pullquote ) || !in_array( $pullquote_position, $valid_positions ) ) {
			return;
		}

		// Find position for pullquote
		$start = 0; // Assume top position, which is the easiest, as it's always 0
		$len   = count( $components );

		// If the position is not top, make some math for middle and bottom
		if ( 'middle' == $pullquote_position ) {
			$start = floor( $len / 3 );         // Start looking at the second third
		} else if ( 'bottom' == $pullquote_position ) {
			$start = floor( ( $len / 4 ) * 3 ); // Start looking at the third quarter
		}

		for ( $position = $start; $position < $len; $position++ ) {
			if ( $components[ $position ]->can_be_anchor_target() ) {
				break;
			}
		}

		// If none was found, do not add
		if ( ! $components[ $position ]->can_be_anchor_target() ) {
			return;
		}

		// Build a new component and set the anchor position to AUTO
		$component = $this->get_component_from_shortname( 'blockquote', "<blockquote>$pullquote</blockquote>" );
		$component->set_anchor_position( Component::ANCHOR_AUTO );
		// Anchor $component to the target component: $components[ $position ]
		$this->anchor_together( $component, $components[ $position ] );

		// Add component in position
		array_splice( $components, $position, 0, array( $component ) );
	}

	/**
	 * Get a component from the shortname.
	 *
	 * @param string $shortname
	 * @param string $html
	 * @return Component
	 * @access private
	 */
	private function get_component_from_shortname( $shortname, $html = null ) {
		return Component_Factory::get_component( $shortname, $html );
	}

	/**
	 * Get a component from a node.
	 *
	 * @param DomNode $node
	 * @return Component
	 * @access private
	 */
	private function get_components_from_node( $node ) {
		return Component_Factory::get_components_from_node( $node );
	}

}
