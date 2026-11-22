import { Body, Button, Html, Text } from "react-email";

export interface ReservationProps {
  name: string;
  workshop: string;
  startsOn: string;
  seats: number;
  code: string;
  url: string;
  kitchen: string;
}

export default function Reservation({ name, workshop, startsOn, seats, code, url, kitchen }: ReservationProps) {
  return (
    <Html lang="en">
      <Body>
        <Text>Hello {name},</Text>
        <Text>We've reserved {seats} seat(s) for {workshop} on {startsOn}. Your booking code is {code}.</Text>
        <Button href={url}>View the workshop</Button>
        <Text>{kitchen}</Text>
      </Body>
    </Html>
  );
}

// `email dev` previews the template with these; `typedpress build` ignores them.
Reservation.PreviewProps = {
  name: "Ada", workshop: "Bread basics", startsOn: "2027-01-15", seats: 2,
  code: "3f0c9a52-6d1b-4c58-9a57-2f4c8e1d7b90", url: "https://example.test/events/bread-basics/", kitchen: "Community Kitchen",
} satisfies ReservationProps;
