# Background

## The Problem

**Tournament scheduling is harder than it looks.** Creating fair, balanced tournaments that ensure every participant gets equal opportunities while respecting constraints (like "no team plays twice in a row" or "avoid repeat matchups") quickly becomes a complex mathematical problem. Unlike other languages that have mature constraint programming libraries and scheduling engines, PHP has been left behind - existing solutions are either unmaintained, limited to basic round-robin only, or built with outdated practices.

**Tactician fills that gap.** Built from the ground up with modern PHP practices, Tactician provides battle-tested algorithms for Round Robin (every participant plays every other participant exactly once), Swiss System (participants paired based on performance after each round), and Pool/Group Play (divide participants into groups with standings). It uses *deterministic* algorithms (meaning the same input always produces the same schedule) with mathematical guarantees of fairness, designed for small to medium tournaments (up to ~50 participants) where you need reliable scheduling without enterprise complexity. For larger tournaments or advanced constraint optimization, consider dedicated scheduling engines.

## About Mission Gaming

**[Mission Gaming](https://missiongaming.gg)** is an esports organization that runs competitive tournaments for EAFC Clubs (11v11 virtual football). Founded and operated by software engineers who are passionate about both competitive gaming and building exceptional technology, we created Tactician to solve our own scheduling challenges on our **Metronome** tournament platform.

After struggling with unmaintained libraries and limited PHP scheduling options, we decided to build the tournament scheduling solution we wished existed—then open source it for the community. We're expanding to other games and building what we believe will be the premier esports tournament platform.

## Sponsorship

<a href="https://www.tag1consulting.com" target="_blank">
  <img src="https://avatars.githubusercontent.com/u/386763?s=200&v=4" alt="Tag1 Consulting" width="200">
</a>

Initial development of this library was sponsored by **[Tag1 Consulting](https://www.tag1consulting.com)**, the absolute legends.  
[Tag1 blog](https://tag1.com/blog) & [Tag1TeamTalks Podcast](https://tag1.com/Tag1TeamTalks)
