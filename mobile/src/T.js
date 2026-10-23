import { forwardRef } from 'react'
import { Text as RNText, Alert } from 'react-native'
import { lang, tr } from './i18n'

/**
 * Drop-in replacement for react-native's Text: in Hindi, any fixed sentence we have a translation for is shown in Hindi (see i18n.js). Strings
 * we have no translation for (customer names, amounts, sentences built from data) are shown unchanged. In English it IS the plain Text.
 */
const walk = (c) => (typeof c === 'string' ? tr(c) : Array.isArray(c) ? c.map(walk) : c)
const HiText = forwardRef(function HiText({ children, ...p }, ref) { return <RNText ref={ref} {...p}>{walk(children)}</RNText> })
export const Text = lang === 'hi' ? HiText : RNText

// alerts too: their title and message are plain strings
if (lang === 'hi' && !Alert.__tr) {
  const orig = Alert.alert.bind(Alert)
  Alert.alert = (title, message, buttons, opts) => orig(tr(title), typeof message === 'string' ? tr(message) : message, Array.isArray(buttons) ? buttons.map(b => ({ ...b, text: tr(b.text) })) : buttons, opts)
  Alert.__tr = true
}
